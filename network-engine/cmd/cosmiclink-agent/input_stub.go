//go:build !windows

package main

import "errors"

func promptCredentials() (string, string, error) {
	return "", "", errors.New("interactive terminal required")
}
func confirm(string, string) error { return errors.New("interactive terminal required") }
