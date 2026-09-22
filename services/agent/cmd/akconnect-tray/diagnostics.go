package main

import (
	"archive/zip"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// "Collect diagnostics" — one click, one file, nothing to type.
//
// The alternative is asking a hotel receptionist to find a log file in
// ProgramData and attach it to an email, which does not happen. This writes a
// single zip on the Desktop and opens the folder so it is under the cursor.
//
// Everything in it is redacted first. A device token is a bearer credential:
// whoever holds it is that device. It is never logged, never echoed and never
// put in a file a customer is about to email — so the redaction is not a
// courtesy here, it is the rule the whole project is built on, applied at the
// one place where files leave the machine.

// secrets is what must never appear in a collected file. Each is matched as a
// whole line and as a JSON value, because the same value shows up both ways.
var secretKeys = []string{
	"device_token", "token", "join_code", "private_key", "privatekey",
	"secret", "password", "authorization",
}

// jsonSecret matches "key": "value" for any of the names above.
var jsonSecret = regexp.MustCompile(
	`(?i)"(` + strings.Join(secretKeys, "|") + `)"\s*:\s*"[^"]*"`)

// assignedSecret matches key: value and key=value in log lines.
var assignedSecret = regexp.MustCompile(
	`(?i)\b(` + strings.Join(secretKeys, "|") + `)\b\s*[:=]\s*\S+`)

// bearer matches an Authorization header however it is spelled.
var bearer = regexp.MustCompile(`(?i)bearer\s+\S+`)

// redact removes credentials from text that is about to leave the machine.
func redact(text string) string {
	// Bearer first, and deliberately. "Authorization: Bearer tok_live_abc" is
	// matched by the assignment rule too, whose \S+ takes only the word
	// "Bearer" — leaving the token itself on the line, one space to the right
	// of a redaction that looked like it had worked. The test for this fails
	// if the order is put back.
	text = bearer.ReplaceAllString(text, "Bearer [redacted]")
	text = jsonSecret.ReplaceAllString(text, `"$1": "[redacted]"`)
	text = assignedSecret.ReplaceAllString(text, `$1: [redacted]`)

	return text
}

// source is one thing collected into the bundle.
type source struct {
	// Name is the file name inside the zip.
	Name string
	// Path is a file to read, or "" when Text is used instead.
	Path string
	// Text is content produced here rather than read from disk.
	Text string
	// Limit caps how much of a large file is taken, from the end: a log that
	// has been running for a year is not more useful than its last megabyte,
	// and a 300 MB attachment does not get sent.
	Limit int64
}

// collect writes the bundle and returns the path it wrote.
func collect(dest string, sources []source, now time.Time) (string, error) {
	path := filepath.Join(dest,
		fmt.Sprintf("%s-diagnostics-%s.zip", productName, now.Format("2006-01-02-1504")))

	file, err := os.Create(path)
	if err != nil {
		return "", fmt.Errorf("could not write to %s: %w", dest, err)
	}
	defer file.Close()

	archive := zip.NewWriter(file)

	for _, src := range sources {
		content, note := contentOf(src)

		entry, err := archive.Create(src.Name)
		if err != nil {
			return "", err
		}
		if note != "" {
			content = note
		}
		if _, err := entry.Write([]byte(redact(content))); err != nil {
			return "", err
		}
	}

	if err := archive.Close(); err != nil {
		return "", err
	}

	return path, nil
}

// contentOf reads a source, or explains why it could not be read. A missing
// file is itself worth knowing — "there is no service log" is an answer.
func contentOf(src source) (content, note string) {
	if src.Path == "" {
		return src.Text, ""
	}

	info, err := os.Stat(src.Path)
	if err != nil {
		return "", fmt.Sprintf("%s could not be read: %v\n", src.Path, err)
	}

	raw, err := os.ReadFile(src.Path)
	if err != nil {
		return "", fmt.Sprintf("%s could not be read: %v\n", src.Path, err)
	}

	if src.Limit > 0 && info.Size() > src.Limit {
		raw = raw[int64(len(raw))-src.Limit:]

		return fmt.Sprintf("[the first %d bytes are not included]\n", info.Size()-src.Limit) +
			string(raw), ""
	}

	return string(raw), ""
}
