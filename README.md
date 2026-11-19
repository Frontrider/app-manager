The application manager for this project
https://github.com/Frontrider/minimal-self-host

# App manager

A simple application to deploy software to the nodes running on your networks.

Intended to be as simple as I can make it, while still being powerful enough.

# Domain support

All applications serving http are deployed on a subpath. This is a deliberate design decision to make [tailscale](https://tailscale.com/) setups simpler (only need to set up 1 api key), 
make it work with [anywherelan](https://anywherelan.com/) and also allow it to work with any DDNS providers.

Some servers don't work with this setup, but it is a worthy sacrifice to simplify operations.

