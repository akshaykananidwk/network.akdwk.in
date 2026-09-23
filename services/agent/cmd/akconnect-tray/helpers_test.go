package main

import (
	"archive/zip"
	"io"
	"os"
	"testing"
)

func writeTestFile(path, content string) error {
	return os.WriteFile(path, []byte(content), 0o644)
}

func readOnly(t *testing.T, bundle, name string) string {
	t.Helper()

	archive, err := zip.OpenReader(bundle)
	if err != nil {
		t.Fatal(err)
	}
	defer archive.Close()

	for _, f := range archive.File {
		if f.Name != name {
			continue
		}

		r, err := f.Open()
		if err != nil {
			t.Fatal(err)
		}
		content, err := io.ReadAll(r)
		if err != nil {
			t.Fatal(err)
		}

		return string(content)
	}

	t.Fatalf("%s is not in the bundle", name)

	return ""
}
