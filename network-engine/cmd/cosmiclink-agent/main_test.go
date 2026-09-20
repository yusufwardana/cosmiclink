package main

import (
	"bytes"
	"errors"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/adminprotocol"
)

func TestOperatorPermissionNoticeSeparatesOperatorWritesFromObserverReads(t *testing.T) {
	var output bytes.Buffer
	printOperatorPermissions(&output)
	notice := output.String()
	for _, expected := range []string{"OPERATOR write access for /ppp/secret/set", "OPERATOR write access for /ppp/active/remove", "separate OBSERVER", "/ppp/secret/print", "/ppp/active/print", "not command-granular", "ENABLE_PPPOE", "DISABLE_PPPOE", "DISCONNECT_SESSION"} {
		if !strings.Contains(notice, expected) {
			t.Fatalf("permission notice missing %q: %s", expected, notice)
		}
	}
}

func TestAddOperatorRequiresConfirmationBeforeHiddenCredentialPrompt(t *testing.T) {
	originalConfirm := confirmEnrollment
	originalPrompt := credentialPrompt
	originalRequest := adminRequest
	defer func() {
		confirmEnrollment = originalConfirm
		credentialPrompt = originalPrompt
		adminRequest = originalRequest
	}()

	confirmed := false
	prompted := false
	var operation adminprotocol.Operation
	var payload adminprotocol.AddOperatorPayload
	confirmEnrollment = func(kind, ref string) error {
		if kind != "ENROLL_OPERATOR" || ref != "" {
			t.Fatalf("confirmation = %q %q", kind, ref)
		}
		confirmed = true
		return nil
	}
	credentialPrompt = func() (string, string, error) {
		if !confirmed {
			t.Fatal("password prompt occurred before destructive-capability confirmation")
		}
		prompted = true
		return "operator-user", "OPERATOR_SENTINEL_SECRET", nil
	}
	adminRequest = func(gotOperation adminprotocol.Operation, gotPayload any) error {
		operation = gotOperation
		payload = gotPayload.(adminprotocol.AddOperatorPayload)
		return nil
	}

	if err := run([]string{"credential", "add-operator", "tenant-1", "router-1"}); err != nil {
		t.Fatal(err)
	}
	if !confirmed || !prompted || operation != adminprotocol.AddOperator {
		t.Fatalf("confirmed=%t prompted=%t operation=%q", confirmed, prompted, operation)
	}
	if payload.TenantRef != "tenant-1" || payload.RouterRef != "router-1" || payload.Username != "operator-user" || payload.Secret != "OPERATOR_SENTINEL_SECRET" || payload.Confirmation != "ENROLL_OPERATOR" {
		t.Fatalf("payload = %#v", payload)
	}
}

func TestAddOperatorStopsBeforePromptWhenConfirmationFails(t *testing.T) {
	originalConfirm := confirmEnrollment
	originalPrompt := credentialPrompt
	defer func() {
		confirmEnrollment = originalConfirm
		credentialPrompt = originalPrompt
	}()

	confirmEnrollment = func(string, string) error { return errors.New("CREDENTIAL_CONFIRMATION_INVALID") }
	credentialPrompt = func() (string, string, error) {
		t.Fatal("credential prompt must not run after failed confirmation")
		return "", "", nil
	}

	if err := run([]string{"credential", "add-operator", "tenant-1", "router-1"}); err == nil || err.Error() != "CREDENTIAL_CONFIRMATION_INVALID" {
		t.Fatalf("error = %v", err)
	}
}

func TestAddOperatorRejectsSecretArguments(t *testing.T) {
	for _, args := range [][]string{
		{"credential", "add-operator", "tenant-1", "router-1", "password"},
		{"credential", "add-operator", "tenant-1", "router-1", "--password", "password"},
		{"credential", "add-operator", "tenant-1", "router-1", "--secret", "password"},
	} {
		if err := run(args); err == nil {
			t.Fatalf("args %#v accepted", args)
		}
	}
}
