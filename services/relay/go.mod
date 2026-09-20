module github.com/akshaykananidwk/network.akdwk.in/services/relay

go 1.24

require github.com/akshaykananidwk/network.akdwk.in/services/shared v0.0.0

require (
	golang.org/x/crypto v0.37.0 // indirect
	golang.org/x/sys v0.32.0 // indirect
)

replace github.com/akshaykananidwk/network.akdwk.in/services/shared => ../shared
