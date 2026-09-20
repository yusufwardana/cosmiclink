package adminprotocol

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
)

const Version = 1

type Operation string

const (
	StoreInit      Operation = "CREDENTIAL_STORE_INIT"
	List           Operation = "CREDENTIAL_LIST"
	Inspect        Operation = "CREDENTIAL_INSPECT"
	AddObserver    Operation = "CREDENTIAL_ADD_OBSERVER"
	AddOperator    Operation = "CREDENTIAL_ADD_OPERATOR"
	TestLocal      Operation = "CREDENTIAL_TEST_LOCAL"
	RotateObserver Operation = "CREDENTIAL_ROTATE_OBSERVER"
	Revoke         Operation = "CREDENTIAL_REVOKE"
	Retire         Operation = "CREDENTIAL_RETIRE"
)

var (
	ErrMalformed          = errors.New("AGENT_PROTOCOL_MALFORMED")
	ErrUnsupportedVersion = errors.New("AGENT_PROTOCOL_VERSION_UNSUPPORTED")
	ErrUnknownOperation   = errors.New("AGENT_PROTOCOL_OPERATION_UNKNOWN")
)

type Request struct {
	Version   int             `json:"version"`
	Operation Operation       `json:"operation"`
	Payload   json.RawMessage `json:"payload"`
}

type Response struct {
	Version int             `json:"version"`
	Result  json.RawMessage `json:"result,omitempty"`
	Error   string          `json:"error,omitempty"`
}

type StoreInitPayload struct{}
type ListPayload struct{}
type CredentialPayload struct {
	CredentialRef string `json:"credential_ref"`
	Version       int    `json:"version"`
	Confirmation  string `json:"confirmation,omitempty"`
}
type AddObserverPayload struct {
	TenantRef string `json:"tenant_ref"`
	RouterRef string `json:"router_ref"`
	Username  string `json:"username"`
	Secret    string `json:"secret"`
}
type AddOperatorPayload struct {
	TenantRef    string `json:"tenant_ref"`
	RouterRef    string `json:"router_ref"`
	Username     string `json:"username"`
	Secret       string `json:"secret"`
	Confirmation string `json:"confirmation"`
}
type RotateObserverPayload struct {
	CredentialRef string `json:"credential_ref"`
	Username      string `json:"username"`
	Secret        string `json:"secret"`
}

func DecodeRequest(data []byte) (Request, error) {
	var request Request
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&request); err != nil {
		return Request{}, ErrMalformed
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return Request{}, ErrMalformed
	}
	if request.Version != Version {
		return Request{}, ErrUnsupportedVersion
	}
	switch request.Operation {
	case StoreInit, List, Inspect, AddObserver, AddOperator, TestLocal, RotateObserver, Revoke, Retire:
	default:
		return Request{}, ErrUnknownOperation
	}
	if len(request.Payload) == 0 || bytes.Equal(bytes.TrimSpace(request.Payload), []byte("null")) {
		return Request{}, ErrMalformed
	}
	return request, nil
}

func DecodePayload(raw json.RawMessage, target any) error {
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		return ErrMalformed
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return ErrMalformed
	}
	return nil
}

func EncodeRequest(operation Operation, payload any) ([]byte, error) {
	encoded, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}
	return json.Marshal(Request{Version: Version, Operation: operation, Payload: encoded})
}

func EncodeResponse(result any, err error) ([]byte, error) {
	response := Response{Version: Version}
	if err != nil {
		response.Error = err.Error()
		return json.Marshal(response)
	}
	encoded, marshalErr := json.Marshal(result)
	if marshalErr != nil {
		return nil, marshalErr
	}
	response.Result = encoded
	return json.Marshal(response)
}
