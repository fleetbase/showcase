# Security

This repository only builds throwaway environments: every workflow run boots a fresh
Fleetbase stack from the published Docker images, seeds it with fictional demo data and
tears it down when the run ends. No customer data and no production system is involved.

If you find a security problem — in this repository's workflows or scripts, or in Fleetbase
itself — please email **hello@fleetbase.io** rather than opening a public issue. Include
enough detail to reproduce it. Fleetbase's full disclosure policy is in the
[main repository](https://github.com/fleetbase/fleetbase/blob/main/SECURITY.md).
