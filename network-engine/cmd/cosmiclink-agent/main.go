package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"strconv"

	"cosmiclink/network-engine/internal/admin"
	"cosmiclink/network-engine/internal/adminprotocol"
	"cosmiclink/network-engine/internal/ipc"
)

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func run(args []string) error {
	if len(args) < 2 || args[0] != "credential" {
		return errors.New("usage: cosmiclink-agent credential <init|list|inspect|add|test|rotate|revoke|retire>")
	}
	for _, arg := range args {
		if arg == "--password" || arg == "--secret" {
			return errors.New("secret flags are not accepted")
		}
	}
	op := args[1]
	var operation adminprotocol.Operation
	var payload any
	switch op {
	case "init":
		operation, payload = adminprotocol.StoreInit, adminprotocol.StoreInitPayload{}
	case "list":
		operation, payload = adminprotocol.List, adminprotocol.ListPayload{}
	case "inspect", "test", "revoke", "retire":
		ref, version, err := parseRef(args[2:])
		if err != nil {
			return err
		}
		if op == "inspect" {
			operation = adminprotocol.Inspect
		}
		if op == "test" {
			operation = adminprotocol.TestLocal
		}
		if op == "revoke" {
			operation = adminprotocol.Revoke
		}
		if op == "retire" {
			operation = adminprotocol.Retire
		}
		if op == "revoke" || op == "retire" {
			if err := confirm(stringsUpper(op), ref); err != nil {
				return err
			}
		}
		confirmation := ""
		if op == "revoke" {
			confirmation = "REVOKE " + ref
		}
		if op == "retire" {
			confirmation = "RETIRE " + ref
		}
		payload = adminprotocol.CredentialPayload{CredentialRef: ref, Version: version, Confirmation: confirmation}
	case "add":
		if len(args) != 4 {
			return errors.New("usage: credential add <tenant_ref> <router_ref>")
		}
		username, secret, err := promptCredentials()
		if err != nil {
			return err
		}
		operation, payload = adminprotocol.AddObserver, adminprotocol.AddObserverPayload{TenantRef: args[2], RouterRef: args[3], Username: username, Secret: secret}
	case "rotate":
		if len(args) != 3 {
			return errors.New("usage: credential rotate <credential_ref>")
		}
		username, secret, err := promptCredentials()
		if err != nil {
			return err
		}
		operation, payload = adminprotocol.RotateObserver, adminprotocol.RotateObserverPayload{CredentialRef: args[2], Username: username, Secret: secret}
	default:
		return errors.New("unknown credential command")
	}
	request, err := adminprotocol.EncodeRequest(operation, payload)
	if err != nil {
		return err
	}
	response, err := ipc.Request(admin.PipeName, request)
	if err != nil {
		return errors.New("AGENT_IPC_UNAVAILABLE")
	}
	var envelope adminprotocol.Response
	decoder := json.NewDecoder(bytes.NewReader(response))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&envelope); err != nil || envelope.Version != adminprotocol.Version {
		return errors.New("AGENT_PROTOCOL_MALFORMED")
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return errors.New("AGENT_PROTOCOL_MALFORMED")
	}
	if envelope.Error != "" {
		return errors.New(envelope.Error)
	}
	if len(envelope.Result) > 0 {
		_, _ = os.Stdout.Write(append(envelope.Result, '\n'))
	}
	return nil
}

func parseRef(args []string) (string, int, error) {
	if len(args) != 2 {
		return "", 0, errors.New("credential reference and version are required")
	}
	v, err := strconv.Atoi(args[1])
	if err != nil || v < 1 {
		return "", 0, errors.New("CREDENTIAL_VERSION_INVALID")
	}
	return args[0], v, nil
}
func stringsUpper(op string) string {
	if op == "revoke" {
		return "REVOKE"
	}
	return "RETIRE"
}
