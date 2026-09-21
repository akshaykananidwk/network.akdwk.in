// Command akconnect-dnstest exercises the agent's DNS configuration code
// against a real systemd-resolved, and reports what happened.
//
// The lab's other DNS checks run inside network namespaces, where
// systemd-resolved cannot see the interfaces and therefore cannot be used.
// What they exercise is the hosts-file fallback — correct on a machine without
// resolved, and not what most of this product's Linux boxes will be: Ubuntu
// 22.04 and 24.04 server both ship it running.
//
// So this runs outside the namespaces, on a throwaway interface the drill
// creates and removes. It is a test fixture rather than a product binary: it
// is not shipped, and it exists because "the resolvectl path is written and
// has never run" is not a sentence to put in a release note.
//
//	akconnect-dnstest -iface veth0 -zone acme.internal -record nvr.acme.internal=10.128.0.50
package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"flag"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"time"

	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/dnsd"
	"github.com/akshaykananidwk/network.akdwk.in/services/agent/internal/netcfg"
)

const hostsPath = "/etc/hosts"

func main() {
	iface := flag.String("iface", "", "interface to configure")
	zone := flag.String("zone", "", "the zone to route")
	records := flag.String("record", "", "name=address, repeatable with commas")
	hold := flag.Duration("hold", 4*time.Second, "how long to leave the configuration in place")
	flag.Parse()

	if *iface == "" || *zone == "" {
		fmt.Fprintln(os.Stderr, "usage: akconnect-dnstest -iface IFACE -zone ZONE [-record name=addr]")
		os.Exit(2)
	}

	if err := run(*iface, *zone, *records, *hold); err != nil {
		fmt.Fprintf(os.Stderr, "dnstest: %v\n", err)
		os.Exit(1)
	}
}

func run(iface, zone, records string, hold time.Duration) error {
	// The hosts file is fingerprinted before anything happens, because "did
	// this path touch the hosts file" is one of the things being asked: on a
	// machine with resolved it must not, and on Windows there is no fallback
	// at all for the same reason.
	before, err := fingerprint(hostsPath)
	if err != nil {
		return err
	}

	server, err := dnsd.New(nil)
	if err != nil {
		return fmt.Errorf("starting the resolver: %w", err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go server.Serve(ctx)

	z := dnsd.NewZone(zone)
	var entries []netcfg.HostEntry
	for _, pair := range strings.Split(records, ",") {
		name, address, ok := strings.Cut(strings.TrimSpace(pair), "=")
		if !ok {
			continue
		}
		z.Add(name, address)
		entries = append(entries, netcfg.HostEntry{Name: name, Address: address})
	}
	server.SetZone(z)

	plan := netcfg.DNSPlan{Interface: iface, Zone: zone, Resolver: server.Addr(), Entries: entries}

	result, err := netcfg.ApplyDNS(plan)
	if err != nil {
		return fmt.Errorf("applying: %w", err)
	}

	fmt.Printf("APPLIED=%s\n", result.Mechanism)
	fmt.Printf("RESOLVER=%s\n", server.Addr().Addr())

	// resolved needs a moment after resolvectl returns before the link's
	// scopes reflect the change.
	time.Sleep(hold)

	// Inspected here rather than by the drill afterwards, because the
	// configuration has to be *live* to be inspected and this process is what
	// removes it. What is printed is systemd-resolved's own output, which the
	// drill then reads — the fixture reports, it does not grade.
	fmt.Println("---STATUS-BEGIN---")
	fmt.Println(commandOutput("resolvectl", "status", iface))
	fmt.Println("---STATUS-END---")

	for _, entry := range entries {
		fmt.Printf("QUERY=%s %s\n", entry.Name,
			strings.ReplaceAll(commandOutput("resolvectl", "query", "--legend=no", entry.Name), "\n", " "))
	}

	after, err := fingerprint(hostsPath)
	if err != nil {
		return err
	}
	if after == before {
		fmt.Println("HOSTS_TOUCHED=no")
	} else {
		fmt.Println("HOSTS_TOUCHED=yes")
	}

	_, _, refused, _ := server.Stats()
	fmt.Printf("REFUSED=%d\n", refused)

	netcfg.RemoveDNS(plan)

	// Reverted is checked by asking the operating system rather than by
	// assuming the call worked.
	if reverted(iface, zone) {
		fmt.Println("REVERTED=yes")
	} else {
		fmt.Println("REVERTED=no")
	}

	final, err := fingerprint(hostsPath)
	if err != nil {
		return err
	}
	if final != before {
		return fmt.Errorf("the hosts file was not restored")
	}

	return nil
}

func fingerprint(path string) (string, error) {
	content, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return "", nil
		}

		return "", fmt.Errorf("reading %s: %w", path, err)
	}

	sum := sha256.Sum256(content)

	return hex.EncodeToString(sum[:]), nil
}

// commandOutput runs a command and returns whatever it said, including on
// failure: an error message from resolvectl is as much evidence as its output.
func commandOutput(name string, args ...string) string {
	out, err := exec.Command(name, args...).CombinedOutput()
	if err != nil && len(out) == 0 {
		return "ERROR: " + err.Error()
	}

	return strings.TrimRight(string(out), "\n")
}
