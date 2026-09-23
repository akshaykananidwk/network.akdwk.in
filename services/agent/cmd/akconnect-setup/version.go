package main

import (
	"strconv"
	"strings"
)

// Version comparison, so running an older setup.exe over a newer install is
// refused rather than silently downgrading a working computer.
//
// It happens: a customer keeps the file their supplier sent in January, the
// panel updates the agent in March, and in June they double-click the January
// file again because it is the one in their Downloads folder. Without this
// they quietly go back to January — with the January bugs — and nobody knows
// why that one PC behaves differently.
//
// Only the numeric part is compared. A prerelease suffix (1.9.5-rc1) is
// ordered below the release it leads to, which is the one rule people expect
// from it; anything more elaborate would be inventing semantics this project
// does not use.
func compareVersions(a, b string) int {
	aNums, aPre := splitVersion(a)
	bNums, bPre := splitVersion(b)

	for i := 0; i < len(aNums) || i < len(bNums); i++ {
		x, y := at(aNums, i), at(bNums, i)
		if x != y {
			if x < y {
				return -1
			}

			return 1
		}
	}

	switch {
	case aPre == bPre:
		return 0
	case aPre == "":
		// A release outranks any prerelease of the same numbers.
		return 1
	case bPre == "":
		return -1
	case aPre < bPre:
		return -1
	default:
		return 1
	}
}

// splitVersion takes "v1.9.5-rc1" to ([1 9 5], "rc1").
func splitVersion(v string) ([]int, string) {
	v = strings.TrimSpace(v)
	v = strings.TrimPrefix(v, "v")

	numeric := v
	pre := ""
	if i := strings.IndexAny(v, "-+"); i >= 0 {
		numeric, pre = v[:i], v[i+1:]
	}

	var nums []int
	for _, part := range strings.Split(numeric, ".") {
		n, err := strconv.Atoi(strings.TrimSpace(part))
		if err != nil {
			// An unparseable part ends the number: "1.9.x" compares as 1.9.
			break
		}
		nums = append(nums, n)
	}

	return nums, pre
}

func at(nums []int, i int) int {
	if i < len(nums) {
		return nums[i]
	}

	return 0
}

// isDowngrade reports whether installing `incoming` over `installed` would go
// backwards. An unknown or unparseable installed version is not a downgrade:
// refusing on a version we cannot read would block a repair on the machine
// that most needs one.
func isDowngrade(incoming, installed string) bool {
	if installed == "" || incoming == "" || incoming == "dev" || installed == "dev" {
		return false
	}

	if nums, _ := splitVersion(installed); len(nums) == 0 {
		return false
	}
	if nums, _ := splitVersion(incoming); len(nums) == 0 {
		return false
	}

	return compareVersions(incoming, installed) < 0
}
