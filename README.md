The application manager for this project
https://github.com/Frontrider/minimal-self-host

# App manager

A simple application to deploy software to the nodes running on your networks.

Intended to be as simple as I can make it, while still being powerful enough.

# Domain support

Every service is deployed behind the same domain, on either a subpath or a separate port. 
Subdomains are explicitly not supported.
This is because a lot of the free solutions to get access to your servers don't give you subdomains (noip, anywherelan, etc)
so simply not building for that makes the system more predictable.

Some services don't work with this setup, but it is a worthy sacrifice to cover as many cases as possible.

