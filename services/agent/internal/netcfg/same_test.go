package netcfg

import (
	"net/netip"
	"testing"
)

func TestAPlanIsTheSameOnlyWhenNothingAboutTheInterfaceChanged(t *testing.T) {
	base := func() *Plan {
		return &Plan{
			Address: netip.MustParsePrefix("10.50.0.2/32"),
			Overlay: netip.MustParsePrefix("10.50.0.0/16"),
			Routes:  []netip.Prefix{netip.MustParsePrefix("10.50.0.0/16"), netip.MustParsePrefix("10.128.5.0/24")},
			MTU:     1280,
		}
	}

	if !base().Same(base()) {
		t.Fatal("two identical plans are not the same")
	}
	for name, change := range map[string]func(*Plan){
		"address": func(p *Plan) { p.Address = netip.MustParsePrefix("10.50.0.3/32") },
		"mtu":     func(p *Plan) { p.MTU = 1420 },
		"route":   func(p *Plan) { p.Routes = p.Routes[:1] },
		"overlay": func(p *Plan) { p.Overlay = netip.MustParsePrefix("10.51.0.0/16") },
	} {
		other := base()
		change(other)
		if base().Same(other) {
			t.Fatalf("a different %s counts as the same plan", name)
		}
	}
	var none *Plan
	if none.Same(base()) || base().Same(nil) {
		t.Fatal("a missing plan counts as the same")
	}

	applied := base()
	applied.installed = []netip.Prefix{netip.MustParsePrefix("10.50.0.0/16")}
	kept := base()
	kept.Carry(applied)
	if len(kept.installed) != 1 {
		t.Fatal("the routes an identical plan installed were not carried over, so Remove would leave them behind")
	}
}
