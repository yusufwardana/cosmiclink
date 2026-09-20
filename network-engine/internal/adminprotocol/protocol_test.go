package adminprotocol

import (
	"errors"
	"testing"
)

func TestProtocolStrictVersionAndOperationValidation(t *testing.T) {
	valid := `{"version":1,"operation":"CREDENTIAL_LIST","payload":{}}`
	if _, err := DecodeRequest([]byte(valid)); err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct {
		name, input string
		want        error
	}{
		{"unknown field", `{"version":1,"operation":"CREDENTIAL_LIST","payload":{},"extra":1}`, ErrMalformed},
		{"trailing", `{"version":1,"operation":"CREDENTIAL_LIST","payload":{}} {}`, ErrMalformed},
		{"version", `{"version":2,"operation":"CREDENTIAL_LIST","payload":{}}`, ErrUnsupportedVersion},
		{"operation", `{"version":1,"operation":"SHELL","payload":{}}`, ErrUnknownOperation},
	} {
		t.Run(tc.name, func(t *testing.T) {
			_, err := DecodeRequest([]byte(tc.input))
			if !errors.Is(err, tc.want) {
				t.Fatalf("error=%v want=%v", err, tc.want)
			}
		})
	}
}

func TestAddOperatorIsDistinctAndRejectsClientOwnedScopeFields(t *testing.T) {
	encoded, err := EncodeRequest(AddOperator, AddOperatorPayload{TenantRef: "tenant-1", RouterRef: "router-1", Username: "operator", Secret: "sentinel", Confirmation: "ENROLL_OPERATOR"})
	if err != nil {
		t.Fatal(err)
	}
	request, err := DecodeRequest(encoded)
	if err != nil {
		t.Fatal(err)
	}
	if request.Operation != AddOperator || request.Operation == AddObserver {
		t.Fatalf("operation = %q", request.Operation)
	}

	for _, payload := range []string{
		`{"tenant_ref":"tenant-1","router_ref":"router-1","username":"operator","secret":"sentinel","confirmation":"ENROLL_OPERATOR","purpose":"OBSERVER"}`,
		`{"tenant_ref":"tenant-1","router_ref":"router-1","username":"operator","secret":"sentinel","confirmation":"ENROLL_OPERATOR","version":7}`,
		`{"tenant_ref":"tenant-1","router_ref":"router-1","username":"operator","secret":"sentinel","confirmation":"ENROLL_OPERATOR","credential_ref":"client-ref"}`,
		`{"tenant_ref":"tenant-1","router_ref":"router-1","username":"operator","secret":"sentinel","confirmation":"ENROLL_OPERATOR","installation_id":"client-installation"}`,
		`{"tenant_ref":"tenant-1","router_ref":"router-1","username":"operator","secret":"sentinel","confirmation":"ENROLL_OPERATOR","agent_ref":"client-agent"}`,
	} {
		var decoded AddOperatorPayload
		if err := DecodePayload([]byte(payload), &decoded); !errors.Is(err, ErrMalformed) {
			t.Fatalf("payload %s error=%v, want malformed", payload, err)
		}
	}
}
