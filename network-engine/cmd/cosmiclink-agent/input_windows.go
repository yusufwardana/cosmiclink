//go:build windows

package main

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"strings"

	"golang.org/x/sys/windows"
)

func promptCredentials() (string, string, error) {
	info, err := os.Stdin.Stat()
	if err != nil || info.Mode()&os.ModeCharDevice == 0 {
		return "", "", errors.New("interactive terminal required")
	}
	in := bufio.NewReader(os.Stdin)
	fmt.Print("Username: ")
	username, err := in.ReadString('\n')
	if err != nil {
		return "", "", errors.New("credential input failed")
	}
	secret, err := hiddenLine(in, "Password: ")
	if err != nil {
		return "", "", err
	}
	confirm, err := hiddenLine(in, "Confirm password: ")
	if err != nil {
		return "", "", err
	}
	username = strings.TrimSpace(username)
	if username == "" || secret == "" {
		return "", "", errors.New("credential input invalid")
	}
	if secret != confirm {
		return "", "", errors.New("CREDENTIAL_CONFIRMATION_INVALID")
	}
	return username, secret, nil
}

func hiddenLine(in *bufio.Reader, prompt string) (string, error) {
	fmt.Print(prompt)
	h := windows.Handle(os.Stdin.Fd())
	var mode uint32
	if err := windows.GetConsoleMode(h, &mode); err != nil {
		return "", errors.New("interactive terminal required")
	}
	defer windows.SetConsoleMode(h, mode)
	if err := windows.SetConsoleMode(h, mode&^windows.ENABLE_ECHO_INPUT); err != nil {
		return "", errors.New("hidden input unavailable")
	}
	line, err := in.ReadString('\n')
	fmt.Println()
	if err != nil {
		return "", errors.New("credential input failed")
	}
	return strings.TrimRight(line, "\r\n"), nil
}

func confirm(kind, ref string) error {
	in := bufio.NewReader(os.Stdin)
	fmt.Printf("Type %s %s: ", kind, ref)
	line, err := in.ReadString('\n')
	if err != nil || strings.TrimSpace(line) != kind+" "+ref {
		return errors.New("CREDENTIAL_CONFIRMATION_INVALID")
	}
	return nil
}
