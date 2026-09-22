<?php

declare(strict_types=1);

namespace Tests;

/**
 * Static checks over the source tree.
 *
 * These catch whole classes of mistake that are easy to make once and then
 * never notice: SQL assembled from user input, a tenant-owned table whose
 * model forgot to declare itself scoped, a named placeholder reused in one
 * statement (which PDO rejects at runtime, not at review time), and a view
 * that echoes a value without escaping it.
 *
 * §5 of the specification asks specifically for the tenant-scope check.
 */
final class StaticAnalysisTests
{
    /** Tables that belong to a customer and must never be queried unscoped. */
    private const TENANT_OWNED_MODELS = [
        'Network', 'Device', 'IpAllocation', 'AclRule', 'NetworkRoute',
        'User', 'ApiKey', 'AuditLog', 'JoinCode', 'Subscription', 'Invoice', 'UsageCounter',
    ];

    public static function run(): void
    {
        self::tenantScopeDeclarations();
        self::noRepeatedPlaceholders();
        self::noInterpolatedSql();
        self::viewsEscapeOutput();
        self::stringAccessorDefaults();
        self::phpSyntax();
    }

    private static function tenantScopeDeclarations(): void
    {
        TestCase::group('Static — every tenant-owned model declares its scope (§5)');

        foreach (self::TENANT_OWNED_MODELS as $model) {
            $class = 'App\\Models\\' . $model;

            if (!class_exists($class)) {
                TestCase::assert(false, $model . ' exists');
                continue;
            }

            TestCase::assert($class::isTenantScoped(), $model . ' is tenant-scoped');
        }

        // Platform-level models must NOT be scoped, or the platform screens
        // would silently return nothing.
        foreach (['Tenant', 'Relay', 'Plan', 'AppUpdate', 'AppBackup', 'MigrationRecord', 'UpdateSetting', 'Job'] as $model) {
            $class = 'App\\Models\\' . $model;
            TestCase::assert(class_exists($class) && !$class::isTenantScoped(),
                $model . ' is platform-level, not tenant-scoped');
        }
    }

    /**
     * Request::input(), query() and cookie() take a ?string default.
     *
     * Production defect (1.9.2): `$request->input('pre_approved', false)` is
     * the obvious way to read a checkbox and is a TypeError. Under
     * declare(strict_types=1) the argument is checked at the call, so it threw
     * on every request that reached the line — both buttons on the join-code
     * page returned 500, not just the one that omitted the field.
     *
     * PHP will not catch this until the line runs, so it is caught here:
     * anything but a quoted string or null is refused. Booleans have
     * Request::boolean(), which has no string default to get wrong.
     */
    private static function stringAccessorDefaults(): void
    {
        TestCase::group('Static — no non-string default reaches a ?string accessor');

        // input/query/cookie take a ?string default second. Setting::get
        // takes a ?int TENANT ID second and its default third — a difference
        // that is invisible at the call site and cost a 500 on every device
        // verification the day this check was extended to cover it. Both
        // shapes are refused the same way: a non-string literal in position
        // two is a mistake in either.
        // What position two must look like, per accessor — they differ, and
        // that is the point. input()/query()/cookie() take a ?string default
        // there; Setting::get() takes a ?int TENANT ID and puts its default
        // third. A string is correct for the first three and is the defect for
        // the fourth: it threw a TypeError on every device verification, so
        // the coordinator could not confirm a single device.
        $accessors = [
            'input'        => 'string',
            'query'        => 'string',
            'cookie'       => 'string',
            'Setting::get' => 'int',
        ];
        $offenders = [];

        foreach (self::phpFiles(true) as $file) {
            // Comments stripped first. The first version of this check
            // reported its own docblock — which quotes the bad call on
            // purpose — and Request::boolean()'s, which does the same. A
            // static check that flags the explanation of the defect is one
            // people turn off.
            $source = self::withoutComments((string) file_get_contents($file));

            foreach ($accessors as $accessor => $expected) {
                // Setting::get is static and is named in full, because
                // Config::get's second argument legitimately IS a mixed
                // default and scanning every ::get( flagged forty of them.
                $needle = str_contains($accessor, '::') ? $accessor . '(' : '->' . $accessor . '(';
                self::scanAccessorCalls($source, $file, $accessor, $needle, $expected, $offenders);
            }
        }

        TestCase::assert(
            $offenders === [],
            'every accessor\'s second argument suits the parameter in that position',
            implode('; ', array_slice($offenders, 0, 5))
        );
    }

    /**
     * @param list<string> $offenders
     */
    private static function scanAccessorCalls(
        string $source,
        string $file,
        string $accessor,
        string $needle,
        string $expected,
        array &$offenders
    ): void {
        $offset = 0;

        while (($position = strpos($source, $needle, $offset)) !== false) {
            $offset = $position + 1;

            $arguments = self::balancedParens($source, $position + strlen($needle) - 1);
            $default = self::secondArgument($arguments);

            if ($default === null || self::argumentFits($default, $expected)) {
                continue;
            }

            $line = substr_count(substr($source, 0, $position), "\n") + 1;
            $offenders[] = self::relative($file) . ':' . $line . ' — ' . $accessor . '(…, ' . $default . ')';
        }
    }

    /**
     * The second argument of a call, or null when there is not one.
     *
     * Deliberately conservative: an argument list containing a nested call or
     * an array is left alone rather than split wrongly, because a static check
     * that reports the wrong line is one people learn to ignore.
     */
    private static function secondArgument(string $arguments): ?string
    {
        $inner = trim($arguments);
        if (str_starts_with($inner, '(')) {
            $inner = substr($inner, 1, -1);
        }

        $depth = 0;
        $quote = '';

        for ($i = 0, $length = strlen($inner); $i < $length; $i++) {
            $char = $inner[$i];

            if ($quote !== '') {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                return trim(substr($inner, $i + 1));
            }
        }

        return null;
    }

    /**
     * Refuse a literal that is not a string.
     *
     * Only literals. A variable or a call is left alone — `query('period',
     * UsageCounter::currentPeriod())` is correct and PHP enforces its type at
     * the call — and the defect this exists for was always a literal: `false`
     * written where the signature wanted `null`.
     */
    /**
     * Does this argument suit the parameter in that position?
     *
     * Variables, constants and calls always pass: their type is the caller's
     * business and PHP enforces it. Only literals are judged, because the
     * defects this exists for were always literals — a `false` where a ?string
     * was wanted, and a `''` where a ?int was.
     */
    private static function argumentFits(string $argument, string $expected): bool
    {
        $argument = trim($argument);

        if (strcasecmp($argument, 'null') === 0) {
            return true;
        }

        if (str_starts_with($argument, "'") || str_starts_with($argument, '"')) {
            return $expected === 'string';
        }

        if (preg_match('/^-?\\d+$/', $argument) === 1) {
            return $expected === 'int';
        }

        // true, false, an array literal: wrong in either position.
        if (preg_match('/^(true|false|\\[|array\\s*\\()/i', $argument) === 1) {
            return false;
        }

        return true;
    }

    /** Source with comments removed, so a docblock cannot be a finding. */
    private static function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                // Replaced by their own newlines, so reported line numbers
                // still point at the real line.
                $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    private static function noRepeatedPlaceholders(): void
    {
        TestCase::group('Static — no named placeholder reused in one statement');

        $offenders = [];

        foreach (self::phpFiles() as $file) {
            $source = (string) file_get_contents($file);
            $offset = 0;

            while (($position = self::findCall($source, $offset)) !== null) {
                [$start, $argsStart] = $position;
                $arguments = self::balancedParens($source, $argsStart);
                $offset = $argsStart + max(1, strlen($arguments));

                preg_match_all('/(?<![:\w]):([a-zA-Z_][a-zA-Z0-9_]*)/', $arguments, $matches);
                $counts = array_count_values($matches[1]);

                foreach ($counts as $name => $count) {
                    if ($count > 1) {
                        $line = substr_count(substr($source, 0, $start), "\n") + 1;
                        $offenders[] = sprintf('%s:%d uses :%s %d times',
                            self::relative($file), $line, $name, $count);
                    }
                }
            }
        }

        TestCase::assert($offenders === [],
            'no statement binds the same placeholder twice',
            $offenders === [] ? '' : implode('; ', array_slice($offenders, 0, 3)));
    }

    private static function noInterpolatedSql(): void
    {
        TestCase::group('Static — SQL is never built from interpolated variables');

        $offenders = [];

        foreach (self::phpFiles() as $file) {
            foreach (self::findSqlRisks((string) file_get_contents($file)) as $risk) {
                $offenders[] = self::relative($file) . ':' . $risk['line'] . ' ' . $risk['detail'];
            }
        }

        TestCase::assert($offenders === [],
            'no SQL statement interpolates or concatenates a variable',
            $offenders === [] ? '' : implode(' | ', array_slice($offenders, 0, 3)));
    }

    /**
     * Find SQL strings that are built from variables.
     *
     * Uses PHP's own tokeniser rather than regular expressions: matching
     * quotes by hand desynchronises on the first apostrophe inside a comment
     * or a single-quoted string, and then reports nonsense.
     *
     * Two shapes are flagged:
     *   "… $var …"          a double-quoted SQL string with interpolation
     *   'SELECT …' . $var   a variable concatenated into a SQL string
     *
     * A concatenated *call* (DB::table(), self::tableName()) is the sanctioned
     * way to name a table and is not flagged.
     *
     * @return list<array{line:int,detail:string}>
     */
    private static function findSqlRisks(string $source): array
    {
        $tokens = token_get_all($source);
        $risks = [];
        $count = count($tokens);

        // A table or column identifier cannot be a bound parameter, so those
        // few places must concatenate. Each one carries an @sql-identifier
        // annotation stating why it is safe; the annotation is what makes the
        // exception reviewable instead of invisible.
        $lines = explode("\n", $source);
        $annotated = [];
        foreach ($lines as $number => $line) {
            if (str_contains($line, '@sql-identifier')) {
                // Cover the annotation's line and the few that follow it.
                for ($offset = 0; $offset <= 6; $offset++) {
                    $annotated[$number + 1 + $offset] = true;
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // --- interpolated double-quoted string ---------------------------
            if ($token === '"') {
                $literal = '';
                $hasVariable = false;
                $line = 0;
                $j = $i + 1;

                for (; $j < $count; $j++) {
                    $inner = $tokens[$j];
                    if ($inner === '"') {
                        break;
                    }
                    if (is_array($inner)) {
                        $line = $line !== 0 ? $line : (int) $inner[2];
                        if ($inner[0] === T_VARIABLE || $inner[0] === T_CURLY_OPEN || $inner[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                            $hasVariable = true;
                        } else {
                            $literal .= $inner[1];
                        }
                    }
                }

                if ($hasVariable && self::looksLikeSql($literal)) {
                    $risks[] = ['line' => $line, 'detail' => 'interpolated SQL: ' . substr(trim($literal), 0, 60)];
                }

                // Resume after the closing quote. Without this the outer loop
                // walks back into the string it just consumed and treats the
                // closing quote as the start of another one, desynchronising
                // every subsequent match in the file.
                $i = $j;
                continue;
            }

            // --- 'SQL' . $variable -------------------------------------------
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && self::looksLikeSql($token[1])) {
                $next = self::nextMeaningful($tokens, $i);
                if ($next !== null && $tokens[$next] === '.') {
                    $after = self::nextMeaningful($tokens, $next);
                    if ($after !== null && is_array($tokens[$after]) && $tokens[$after][0] === T_VARIABLE) {
                        // A method call on the variable is a call, not a raw
                        // value — $pdo->quote() is PDO's own escaping.
                        $following = self::nextMeaningful($tokens, $after);
                        $isCall = $following !== null
                            && is_array($tokens[$following])
                            && in_array($tokens[$following][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true);

                        if (!$isCall) {
                            $risks[] = [
                                'line'   => (int) $token[2],
                                'detail' => 'concatenated variable ' . $tokens[$after][1] . ' after SQL literal',
                            ];
                        }
                    }
                }
            }
        }

        return array_values(array_filter(
            $risks,
            static fn (array $risk): bool => !isset($annotated[$risk['line']])
        ));
    }

    /** A SQL verb plus a clause keyword, so prose like "Update #12" is ignored. */
    private static function looksLikeSql(string $literal): bool
    {
        return preg_match('/(SELECT\s+[\w`*(]|INSERT\s+INTO\s+[\w`]|UPDATE\s+[\w`]|DELETE\s+FROM\s+[\w`])/i', $literal) === 1
            && preg_match('/\b(FROM|WHERE|SET|VALUES|INTO|JOIN)\b/i', $literal) === 1;
    }

    /** @param list<mixed> $tokens */
    private static function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from + 1; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private static function viewsEscapeOutput(): void
    {
        TestCase::group('Static — views escape everything they echo');

        $offenders = [];

        foreach (self::viewFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach (explode("\n", $source) as $number => $line) {
                if (preg_match_all('/<\?=\s*(.+?)\s*\?>/', $line, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $expression) {
                    if (!self::expressionIsEscaped(trim($expression))) {
                        $offenders[] = self::relative($file) . ':' . ($number + 1) . '  <?= ' . trim($expression);
                    }
                }
            }
        }

        TestCase::assert($offenders === [],
            'every value a view echoes is escaped, cast or a literal',
            $offenders === [] ? '' : implode(' | ', array_slice($offenders, 0, 4))
                . (count($offenders) > 4 ? ' (+' . (count($offenders) - 4) . ' more)' : ''));
    }

    /**
     * Is every value this expression could output escaped?
     *
     * The rule is about *outputs*, not the whole expression: a ternary's
     * condition is never printed, so `$a === $b ? \'selected\' : \'\'` is safe even
     * though it mentions variables. The check therefore reduces the expression
     * to just the things it can emit, then insists nothing raw is left.
     */
    private static function expressionIsEscaped(string $expression): bool
    {
        // Constructs that return already-escaped markup.
        foreach (['csrf_field()', '\App\Core\View::partial', '\App\Core\View::section', '$content', '$body'] as $safe) {
            if (str_starts_with($expression, $safe)) {
                return true;
            }
        }

        $reduced = $expression;

        // 1. Escaping helpers and numeric-only helpers produce safe output.
        //    These remove balanced calls, so parentheses stay balanced for
        //    the ternary walk below.
        foreach (['e', 'e_attr', 'e_js', 'h', 'count', 'number_format', 'chunk_split'] as $helper) {
            $reduced = self::stripCalls($reduced, $helper);
        }

        // 2. Drop every ternary condition — conditions are never printed.
        //    This runs before cast stripping, which would unbalance the
        //    parentheses the walk depends on.
        $reduced = self::stripTernaryConditions($reduced);

        // 3. A cast to a number cannot carry markup.
        $reduced = (string) preg_replace(
            '/\(\s*(int|integer|float|double|bool|boolean)\s*\)\s*\$[a-zA-Z_][a-zA-Z0-9_]*(\[[^\]]*\])*/',
            'SAFE',
            $reduced
        );

        // Anything still referencing a variable is emitted unescaped.
        return preg_match('/\$[a-zA-Z_]/', $reduced) !== 1;
    }

    /** Replace `name( ... )` calls, balancing parentheses, with a marker. */
    private static function stripCalls(string $expression, string $function): string
    {
        $pattern = '/(?<![a-zA-Z0-9_])' . preg_quote($function, '/') . '\s*\(/';

        while (preg_match($pattern, $expression, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $start = (int) $matches[0][1];
            $open = $start + strlen($matches[0][0]) - 1;
            $inner = self::balancedParens($expression, $open);
            $end = $open + strlen($inner) + 2;

            $expression = substr($expression, 0, $start) . 'SAFE' . substr($expression, $end);
        }

        return $expression;
    }

    /**
     * Remove the condition part of every ternary, at any nesting depth,
     * leaving only the branches that can actually be printed.
     *
     * Walks the string tracking quotes and bracket depth, and remembers where
     * the current group started, so `a ? x : (b ? y : z)` reduces to the
     * literals `x`, `y` and `z` rather than tripping over `b`.
     */
    private static function stripTernaryConditions(string $expression): string
    {
        // Each pass removes at most one condition; repeat until stable.
        for ($pass = 0; $pass < 32; $pass++) {
            $groupStart = 0;
            $stack = [];
            $quote = null;
            $length = strlen($expression);
            $edited = false;

            for ($i = 0; $i < $length; $i++) {
                $char = $expression[$i];

                if ($quote !== null) {
                    if ($char === '\\') {
                        $i++;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($char === "'" || $char === '"') {
                    $quote = $char;
                    continue;
                }

                if ($char === '(' || $char === '[') {
                    $stack[] = $groupStart;
                    $groupStart = $i + 1;
                    continue;
                }

                if ($char === ')' || $char === ']') {
                    $groupStart = array_pop($stack) ?? 0;
                    continue;
                }

                // A branch boundary starts a fresh group, so a ternary in the
                // else-branch is stripped from there rather than from the top.
                if ($char === ':' || $char === ',') {
                    if (($expression[$i + 1] ?? '') !== ':' && ($expression[$i - 1] ?? '') !== ':') {
                        $groupStart = $i + 1;
                    }
                    continue;
                }

                if ($char === '?') {
                    // `??` is null coalescing: both sides are printed, so it
                    // is not a condition.
                    if (($expression[$i + 1] ?? '') === '?') {
                        $i++;
                        continue;
                    }

                    $expression = substr($expression, 0, $groupStart) . ' ' . substr($expression, $i + 1);
                    $edited = true;
                    break;
                }
            }

            if (!$edited) {
                break;
            }
        }

        return $expression;
    }

    /** @return list<string> */
    private static function viewFiles(): array
    {
        $files = [];
        $path = APP_ROOT . '/app/Views';

        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach (glob(APP_ROOT . '/install/views/*.php') ?: [] as $file) {
            $files[] = $file;
        }

        sort($files);

        return $files;
    }

    private static function phpSyntax(): void
    {
        TestCase::group('Static — every PHP file parses');

        $checked = 0;
        $errors = [];

        foreach (self::phpFiles(true) as $file) {
            $source = (string) file_get_contents($file);
            $checked++;

            try {
                token_get_all($source, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $errors[] = self::relative($file) . ': ' . $e->getMessage();
            }
        }

        TestCase::assert($errors === [], $checked . ' PHP files parse cleanly',
            $errors === [] ? '' : implode(' | ', $errors));
    }

    /** @return list<string> */
    private static function phpFiles(bool $includeViews = false): array
    {
        $directories = ['app', 'cli', 'install', 'database', 'tests'];
        $files = [];

        foreach ($directories as $directory) {
            $path = APP_ROOT . '/' . $directory;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                if (!$includeViews && str_contains($file->getPathname(), '/Views/')) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        foreach (['index.php', 'health.php'] as $root) {
            if (is_file(APP_ROOT . '/' . $root)) {
                $files[] = APP_ROOT . '/' . $root;
            }
        }

        sort($files);

        return $files;
    }

    /** @return array{0:int,1:int}|null */
    private static function findCall(string $source, int $offset): ?array
    {
        if (preg_match('/DB::(execute|query|select|selectOne|scalar)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            return null;
        }

        $start = (int) $matches[0][1];

        return [$start, $start + strlen($matches[0][0]) - 1];
    }

    private static function balancedParens(string $source, int $openPosition): string
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $openPosition; $i < $length; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $openPosition + 1, $i - $openPosition - 1);
                }
            }
        }

        return '';
    }

    private static function relative(string $file): string
    {
        return str_replace(APP_ROOT . '/', '', $file);
    }
}
