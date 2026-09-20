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
