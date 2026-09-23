module github.com/akshaykananidwk/network.akdwk.in/services/coordinator

go 1.24

replace github.com/akshaykananidwk/network.akdwk.in/services/shared => ../shared

require (
	github.com/akshaykananidwk/network.akdwk.in/services/shared v0.0.0-00010101000000-000000000000
	golang.org/x/crypto v0.37.0
)

require golang.org/x/sys v0.32.0 // indirect
