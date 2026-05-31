# ASD STIG wizard — SME review sheet

> Auto-generated from `asd.json` (ASD STIG **V6R4**, 286 rules). Regenerate after editing the schema — do not hand-edit. Every row below is an **automated suggestion**; confirm or correct each determination.

**144 status-changing determinations** across **138 requirements** · 55 comment-only · 90 left `Not_Reviewed`.

By confidence: 🔴 low=20 · 🟡 medium=59 · 🟢 high=65

## 1. Status-changing determinations (review every row)

Sorted **low confidence first** — those need the most scrutiny. Tick the ✔ column as you confirm each.

| ✔ | Conf | V-ID | Rule (APSC-DV) | → Status | Triggered when | Requirement |
|---|------|------|----------------|----------|----------------|-------------|
|  | 🔴 low | V-222434 | APSC-DV-000550 | Not_Applicable | has_user_accounts=no | The application must display the Standard Mandatory DoD Notice and Consent Banner before g… |
|  | 🔴 low | V-222435 | APSC-DV-000560 | Not_Applicable | has_user_accounts=no | The application must retain the Standard Mandatory DoD Notice and Consent Banner on the sc… |
|  | 🔴 low | V-222439 | APSC-DV-000600 | Not_Applicable | audit_self_aggregation=no | For applications providing audit record aggregation, the application must compile audit re… |
|  | 🔴 low | V-222487 | APSC-DV-001130 | Not_Applicable | audit_self_aggregation=no | The application must provide the capability to centrally review and analyze audit records … |
|  | 🔴 low | V-222489 | APSC-DV-001150 | Not_Applicable | audit_self_aggregation=no | The application must provide an audit reduction capability that supports on-demand reporti… |
|  | 🔴 low | V-222490 | APSC-DV-001160 | Not_Applicable | audit_self_aggregation=no | The application must provide an audit reduction capability that supports on-demand audit r… |
|  | 🔴 low | V-222491 | APSC-DV-001170 | Not_Applicable | audit_self_aggregation=no | The application must provide an audit reduction capability that supports after-the-fact in… |
|  | 🔴 low | V-222492 | APSC-DV-001180 | Not_Applicable | audit_self_aggregation=no | The application must provide a report generation capability that supports on-demand audit … |
|  | 🔴 low | V-222493 | APSC-DV-001190 | Not_Applicable | audit_self_aggregation=no | The application must provide a report generation capability that supports on-demand report… |
|  | 🔴 low | V-222494 | APSC-DV-001200 | Not_Applicable | audit_self_aggregation=no | The application must provide a report generation capability that supports after-the-fact i… |
|  | 🔴 low | V-222495 | APSC-DV-001210 | Not_Applicable | audit_self_aggregation=no | The application must provide an audit reduction capability that does not alter original co… |
|  | 🔴 low | V-222496 | APSC-DV-001220 | Not_Applicable | audit_self_aggregation=no | The application must provide a report generation capability that does not alter original c… |
|  | 🔴 low | V-222523 | APSC-DV-001550 | Not_Applicable | has_user_accounts=no | The application must use multifactor (Alt. Token) authentication for network access to pri… |
|  | 🔴 low | V-222526 | APSC-DV-001580 | Not_Applicable | has_user_accounts=no | The application must use multifactor (e.g., CAC, Alt. Token) authentication for network ac… |
|  | 🔴 low | V-222527 | APSC-DV-001590 | Not_Applicable | has_user_accounts=no | The application must use multifactor (Alt. Token) authentication for local access to privi… |
|  | 🔴 low | V-222528 | APSC-DV-001600 | Not_Applicable | has_user_accounts=no | The application must use multifactor (e.g., CAC, Alt. Token) authentication for local acce… |
|  | 🔴 low | V-222530 | APSC-DV-001620 | Not_Applicable | has_user_accounts=no | The application must implement replay-resistant authentication mechanisms for network acce… |
|  | 🔴 low | V-222531 | APSC-DV-001630 | Not_Applicable | has_user_accounts=no | The application must implement replay-resistant authentication mechanisms for network acce… |
|  | 🔴 low | V-222620 | APSC-DV-002890 | Not_Applicable | internet_accessible=no | Application web servers must be on a separate network segment from the application and dat… |
|  | 🔴 low | V-222671 | APSC-DV-003350 | Not_Applicable | internet_accessible=no | Connections between the DoD enclave and the Internet or other public or commercial wide ar… |
|  | 🟡 med | V-222387 | APSC-DV-000010 | Not_Applicable | has_user_accounts=no | The application must provide a capability to limit the number of logon sessions per user. |
|  | 🟡 med | V-222396 | APSC-DV-000160 | Not_Applicable | supports_remote_access=no | The application must implement DoD-approved encryption to protect the confidentiality of r… |
|  | 🟡 med | V-222397 | APSC-DV-000170 | Not_Applicable | supports_remote_access=no | The application must implement cryptographic mechanisms to protect the integrity of remote… |
|  | 🟡 med | V-222407 | APSC-DV-000280 | Not_Applicable | has_user_accounts=no | The application must provide automated mechanisms for supporting account management functi… |
|  | 🟡 med | V-222408 | APSC-DV-000290 | Not_Applicable | has_user_accounts=no | Shared/group account credentials must be terminated when members leave the group. |
|  | 🟡 med | V-222409 | APSC-DV-000300 | Not_Applicable | has_user_accounts=no | The application must automatically remove or disable temporary user accounts 72 hours afte… |
|  | 🟡 med | V-222410 | APSC-DV-000310 | Not_Applicable | has_user_accounts=no | The application must have a process, feature or function that prevents removal or disablin… |
|  | 🟡 med | V-222411 | APSC-DV-000320 | Not_Applicable | has_user_accounts=no | The application must automatically disable accounts after a 35 day period of account inact… |
|  | 🟡 med | V-222412 | APSC-DV-000330 | Not_Applicable | has_user_accounts=no | Unnecessary application accounts must be disabled, or deleted. |
|  | 🟡 med | V-222413 | APSC-DV-000340 | Not_Applicable | has_user_accounts=no | The application must automatically audit account creation. |
|  | 🟡 med | V-222414 | APSC-DV-000350 | Not_Applicable | has_user_accounts=no | The application must automatically audit account modification. |
|  | 🟡 med | V-222415 | APSC-DV-000360 | Not_Applicable | has_user_accounts=no | The application must automatically audit account disabling actions. |
|  | 🟡 med | V-222416 | APSC-DV-000370 | Not_Applicable | has_user_accounts=no | The application must automatically audit account removal actions. |
|  | 🟡 med | V-222417 | APSC-DV-000380 | Not_Applicable | has_user_accounts=no | The application must notify system administrators (SAs) and information system security of… |
|  | 🟡 med | V-222418 | APSC-DV-000390 | Not_Applicable | has_user_accounts=no | The application must notify system administrators (SAs) and information system security of… |
|  | 🟡 med | V-222419 | APSC-DV-000400 | Not_Applicable | has_user_accounts=no | The application must notify system administrators (SAs) and information system security of… |
|  | 🟡 med | V-222420 | APSC-DV-000410 | Not_Applicable | has_user_accounts=no | The application must notify system administrators (SAs) and information system security of… |
|  | 🟡 med | V-222421 | APSC-DV-000420 | Not_Applicable | has_user_accounts=no | The application must automatically audit account enabling actions. |
|  | 🟡 med | V-222422 | APSC-DV-000430 | Not_Applicable | has_user_accounts=no | The application must notify system administrators (SAs) and information system security of… |
|  | 🟡 med | V-222430 | APSC-DV-000510 | Not_Applicable | has_user_accounts=no | The application must execute without excessive account permissions. |
|  | 🟡 med | V-222432 | APSC-DV-000530 | Not_Applicable | has_user_accounts=no | The application must enforce the limit of three consecutive invalid logon attempts by a us… |
|  | 🟡 med | V-222433 | APSC-DV-000540 | Not_Applicable | has_user_accounts=no | The application administrator must follow an approved process to unlock locked user accoun… |
|  | 🟡 med | V-222437 | APSC-DV-000580 | Not_Applicable | has_user_accounts=no | The application must display the time and date of the users last successful logon. |
|  | 🟡 med | V-222453 | APSC-DV-000740 | Not_Applicable | data_classification=Unclassified (no CUI) | The application must generate audit records when successful/unsuccessful attempts to acces… |
|  | 🟡 med | V-222457 | APSC-DV-000780 | Not_Applicable | data_classification=Unclassified (no CUI) | The application must generate audit records when successful/unsuccessful attempts to modif… |
|  | 🟡 med | V-222461 | APSC-DV-000820 | Not_Applicable | data_classification=Unclassified (no CUI) | The application must generate audit records when successful/unsuccessful attempts to delet… |
|  | 🟡 med | V-222462 | APSC-DV-000830 | Not_Applicable | has_user_accounts=no | The application must generate audit records when successful/unsuccessful logon attempts oc… |
|  | 🟡 med | V-222467 | APSC-DV-000880 | Not_Applicable | has_user_accounts=no | The application must generate audit records for all account creations, modifications, disa… |
|  | 🟡 med | V-222478 | APSC-DV-001030 | Not_Applicable | has_user_accounts=no | The application must generate audit records containing the full-text recording of privileg… |
|  | 🟡 med | V-222515 | APSC-DV-001460 | Not_Applicable | software_origin=COTS / third-party | An application vulnerability assessment must be conducted. |
|  | 🟡 med | V-222524 | APSC-DV-001560 | Not_Applicable | uses_pki=no | The application must accept Personal Identity Verification (PIV) credentials. |
|  | 🟡 med | V-222525 | APSC-DV-001570 | Not_Applicable | uses_pki=no | The application must electronically verify Personal Identity Verification (PIV) credential… |
|  | 🟡 med | V-222529 | APSC-DV-001610 | Not_Applicable | has_user_accounts=no | The application must ensure users are authenticated with an individual authenticator prior… |
|  | 🟡 med | V-222534 | APSC-DV-001660 | Not_Applicable | is_web_service=no | Service-Oriented Applications handling non-releasable data must authenticate endpoint devi… |
|  | 🟡 med | V-222549 | APSC-DV-001800 | Not_Applicable | has_user_accounts=no | The application must terminate existing user sessions upon account deletion. |
|  | 🟡 med | V-222595 | APSC-DV-002410 | Not_Applicable | is_web_service=no | The web service design must include redundancy mechanisms when used with high-availability… |
|  | 🟡 med | V-222618 | APSC-DV-002870 | Not_Applicable | uses_mobile_code=no | Unsigned Category 1A mobile code must not be used in the application in accordance with Do… |
|  | 🟡 med | V-222619 | APSC-DV-002880 | Not_Applicable | has_user_accounts=no | The ISSO must ensure an account management process is implemented, verifying only authoriz… |
|  | 🟡 med | V-222624 | APSC-DV-002930 | Not_Applicable | software_origin=COTS / third-party | The ISSO must ensure active vulnerability testing is performed. |
|  | 🟡 med | V-222625 | APSC-DV-002950 | Not_Applicable | is_web_service=no | Execution flow diagrams and design documents must be created to show how deadlock and recu… |
|  | 🟡 med | V-222625 | APSC-DV-002950 | Not_Applicable | software_origin=COTS / third-party | Execution flow diagrams and design documents must be created to show how deadlock and recu… |
|  | 🟡 med | V-222632 | APSC-DV-003010 | Not_Applicable | software_origin=COTS / third-party | A Software Configuration Management (SCM) plan describing the configuration control and ch… |
|  | 🟡 med | V-222633 | APSC-DV-003020 | Not_Applicable | software_origin=COTS / third-party | A Configuration Control Board (CCB) that meets at least every release cycle, for managing … |
|  | 🟡 med | V-222644 | APSC-DV-003130 | Not_Applicable | software_origin=COTS / third-party | Prior to each release of the application, updates to system, or applying patches; tests pl… |
|  | 🟡 med | V-222645 | APSC-DV-003140 | Not_Applicable | software_origin=COTS / third-party | Application files must be cryptographically hashed prior to deploying to DoD operational n… |
|  | 🟡 med | V-222646 | APSC-DV-003150 | Not_Applicable | software_origin=COTS / third-party | At least one tester must be designated to test for security flaws in addition to functiona… |
|  | 🟡 med | V-222647 | APSC-DV-003160 | Not_Applicable | software_origin=COTS / third-party | Test procedures must be created and at least annually executed to ensure system initializa… |
|  | 🟡 med | V-222648 | APSC-DV-003170 | Not_Applicable | software_origin=COTS / third-party | An application code review must be performed on the application. |
|  | 🟡 med | V-222649 | APSC-DV-003180 | Not_Applicable | software_origin=COTS / third-party | Code coverage statistics must be maintained for each release of the application. |
|  | 🟡 med | V-222650 | APSC-DV-003190 | Not_Applicable | software_origin=COTS / third-party | Flaws found during a code review must be tracked in a defect tracking system. |
|  | 🟡 med | V-222652 | APSC-DV-003210 | Not_Applicable | software_origin=COTS / third-party | Security flaws must be fixed or addressed in the project plan. |
|  | 🟡 med | V-222653 | APSC-DV-003215 | Not_Applicable | software_origin=COTS / third-party | The application development team must follow a set of coding standards. |
|  | 🟡 med | V-222654 | APSC-DV-003220 | Not_Applicable | software_origin=COTS / third-party | The designer must create and update the Design Document for each release of the applicatio… |
|  | 🟡 med | V-222655 | APSC-DV-003230 | Not_Applicable | software_origin=COTS / third-party | Threat models must be documented and reviewed for each application release and updated as … |
|  | 🟡 med | V-222657 | APSC-DV-003236 | Not_Applicable | software_origin=COTS / third-party | The application development team must provide an application incident response plan. |
|  | 🟡 med | V-222661 | APSC-DV-003270 | Not_Applicable | has_user_accounts=no | Unnecessary built-in application accounts must be disabled. |
|  | 🟡 med | V-222665 | APSC-DV-003300 | Not_Applicable | uses_mobile_code=no | The designer must ensure uncategorized or emerging mobile code is not used in applications… |
|  | 🟡 med | V-222672 | APSC-DV-003360 | Not_Applicable | has_user_accounts=no | The application must generate audit records when concurrent logons from different workstat… |
|  | 🟡 med | V-222673 | APSC-DV-003400 | Not_Applicable | software_origin=COTS / third-party | The Program Manager must verify all levels of program management, designers, developers, a… |
|  | 🟢 high | V-222388 | APSC-DV-000060 | Not_Applicable | web_based=no | The application must clear temporary storage and cookies when the session is terminated. |
|  | 🟢 high | V-222398 | APSC-DV-000180 | Not_Applicable | uses_soap_saml=no | Applications with SOAP messages requiring integrity must include the following message ele… |
|  | 🟢 high | V-222399 | APSC-DV-000190 | Not_Applicable | uses_soap_saml=no | Messages protected with WS_Security must use time stamps with creation and expiration time… |
|  | 🟢 high | V-222400 | APSC-DV-000200 | Not_Applicable | uses_soap_saml=no | Validity periods must be verified on all application messages using WS-Security or SAML as… |
|  | 🟢 high | V-222401 | APSC-DV-000210 | Not_Applicable | uses_soap_saml=no | The application must ensure each unique asserting party provides unique assertion ID refer… |
|  | 🟢 high | V-222402 | APSC-DV-000220 | Not_Applicable | uses_soap_saml=no | The application must ensure encrypted assertions, or equivalent confidentiality protection… |
|  | 🟢 high | V-222403 | APSC-DV-000230 | Not_Applicable | uses_soap_saml=no | The application must use the NotOnOrAfter condition when using the SubjectConfirmation ele… |
|  | 🟢 high | V-222404 | APSC-DV-000240 | Not_Applicable | uses_soap_saml=no | The application must use both the NotBefore and NotOnOrAfter elements or OneTimeUse elemen… |
|  | 🟢 high | V-222405 | APSC-DV-000250 | Not_Applicable | uses_soap_saml=no | The application must ensure if a OneTimeUse element is used in an assertion, there is only… |
|  | 🟢 high | V-222406 | APSC-DV-000260 | Not_Applicable | uses_soap_saml=no | The application must ensure messages are encrypted when the SessionIndex is tied to privac… |
|  | 🟢 high | V-222408 | APSC-DV-000290 | Not_Applicable | has_user_accounts=yes / account_kinds=not:Shared / group accounts | Shared/group account credentials must be terminated when members leave the group. |
|  | 🟢 high | V-222409 | APSC-DV-000300 | Not_Applicable | has_user_accounts=yes / account_kinds=not:Temporary accounts | The application must automatically remove or disable temporary user accounts 72 hours afte… |
|  | 🟢 high | V-222410 | APSC-DV-000310 | Not_Applicable | has_user_accounts=yes / account_kinds=not:Emergency accounts | The application must have a process, feature or function that prevents removal or disablin… |
|  | 🟢 high | V-222436 | APSC-DV-000570 | Not_Applicable | internet_accessible=no | The publicly accessible application must display the Standard Mandatory DoD Notice and Con… |
|  | 🟢 high | V-222460 | APSC-DV-000810 | Not_Applicable | uses_database=no | The application must generate audit records when successful/unsuccessful attempts to delet… |
|  | 🟢 high | V-222478 | APSC-DV-001030 | Not_Applicable | has_user_accounts=yes / account_kinds=not:Shared / group accounts | The application must generate audit records containing the full-text recording of privileg… |
|  | 🟢 high | V-222479 | APSC-DV-001040 | Not_Applicable | transaction_based=no | The application must implement transaction recovery logs when transaction based. |
|  | 🟢 high | V-222529 | APSC-DV-001610 | Not_Applicable | has_user_accounts=yes / account_kinds=not:Shared / group accounts | The application must ensure users are authenticated with an individual authenticator prior… |
|  | 🟢 high | V-222536 | APSC-DV-001680 | Not_Applicable | uses_passwords=no | The application must enforce a minimum 15-character password length. |
|  | 🟢 high | V-222537 | APSC-DV-001690 | Not_Applicable | uses_passwords=no | The application must enforce password complexity by requiring that at least one uppercase … |
|  | 🟢 high | V-222538 | APSC-DV-001700 | Not_Applicable | uses_passwords=no | The application must enforce password complexity by requiring that at least one lowercase … |
|  | 🟢 high | V-222539 | APSC-DV-001710 | Not_Applicable | uses_passwords=no | The application must enforce password complexity by requiring that at least one numeric ch… |
|  | 🟢 high | V-222540 | APSC-DV-001720 | Not_Applicable | uses_passwords=no | The application must enforce password complexity by requiring that at least one special ch… |
|  | 🟢 high | V-222541 | APSC-DV-001730 | Not_Applicable | uses_passwords=no | The application must require the change of at least eight of the total number of character… |
|  | 🟢 high | V-222542 | APSC-DV-001740 | Not_Applicable | uses_passwords=no | The application must only store cryptographic representations of passwords. |
|  | 🟢 high | V-222543 | APSC-DV-001750 | Not_Applicable | uses_passwords=no | The application must transmit only cryptographically-protected passwords. |
|  | 🟢 high | V-222544 | APSC-DV-001760 | Not_Applicable | uses_passwords=no | The application must enforce 24 hours/1 day as the minimum password lifetime. |
|  | 🟢 high | V-222545 | APSC-DV-001770 | Not_Applicable | uses_passwords=no | The application must enforce a 60-day maximum password lifetime restriction. |
|  | 🟢 high | V-222546 | APSC-DV-001780 | Not_Applicable | uses_passwords=no | The application must prohibit password reuse for a minimum of five generations. |
|  | 🟢 high | V-222547 | APSC-DV-001790 | Not_Applicable | uses_passwords=no | The application must allow the use of a temporary password for system logons with an immed… |
|  | 🟢 high | V-222548 | APSC-DV-001795 | Not_Applicable | uses_passwords=no | The application password must not be changeable by users other than the administrator or t… |
|  | 🟢 high | V-222550 | APSC-DV-001810 | Not_Applicable | uses_pki=no | The application, when utilizing PKI-based authentication, must validate certificates by co… |
|  | 🟢 high | V-222551 | APSC-DV-001820 | Not_Applicable | uses_pki=no | The application, when using PKI-based authentication, must enforce authorized access to th… |
|  | 🟢 high | V-222552 | APSC-DV-001830 | Not_Applicable | uses_pki=no | The application must map the authenticated identity to the individual user or group accoun… |
|  | 🟢 high | V-222553 | APSC-DV-001840 | Not_Applicable | uses_pki=no | The application, for PKI-based authentication, must implement a local cache of revocation … |
|  | 🟢 high | V-222554 | APSC-DV-001850 | Not_Applicable | uses_passwords=no | The application must not display passwords/PINs as clear text. |
|  | 🟢 high | V-222557 | APSC-DV-001880 | Not_Applicable | federated_external_users=no | The application must accept Personal Identity Verification (PIV) credentials from other fe… |
|  | 🟢 high | V-222558 | APSC-DV-001890 | Not_Applicable | federated_external_users=no | The application must electronically verify Personal Identity Verification (PIV) credential… |
|  | 🟢 high | V-222559 | APSC-DV-001900 | Not_Applicable | federated_external_users=no | The application must accept Federal Identity, Credential, and Access Management (FICAM)-ap… |
|  | 🟢 high | V-222560 | APSC-DV-001910 | Not_Applicable | federated_external_users=no | The application must conform to Federal Identity, Credential, and Access Management (FICAM… |
|  | 🟢 high | V-222561 | APSC-DV-001930 | Not_Applicable | non_local_maintenance=no | Applications used for non-local maintenance sessions must audit non-local maintenance and … |
|  | 🟢 high | V-222562 | APSC-DV-001940 | Not_Applicable | non_local_maintenance=no | Applications used for non-local maintenance sessions must implement cryptographic mechanis… |
|  | 🟢 high | V-222563 | APSC-DV-001950 | Not_Applicable | non_local_maintenance=no | Applications used for non-local maintenance sessions must implement cryptographic mechanis… |
|  | 🟢 high | V-222564 | APSC-DV-001960 | Not_Applicable | non_local_maintenance=no | Applications used for non-local maintenance sessions must verify remote disconnection at t… |
|  | 🟢 high | V-222565 | APSC-DV-001970 | Not_Applicable | non_local_maintenance=no | The application must employ strong authenticators in the establishment of non-local mainte… |
|  | 🟢 high | V-222566 | APSC-DV-001980 | Not_Applicable | non_local_maintenance=no | The application must terminate all sessions and network connections when nonlocal maintena… |
|  | 🟢 high | V-265634 | APSC-DV-002010 | Not_Applicable | data_classification=Unclassified (no CUI) | The application must implement NSA-approved cryptography to protect classified information… |
|  | 🟢 high | V-222573 | APSC-DV-002050 | Not_Applicable | uses_soap_saml=no | Applications making SAML assertions must use FIPS-approved random numbers in the generatio… |
|  | 🟢 high | V-222575 | APSC-DV-002210 | Not_Applicable | web_based=no | The application must set the HTTPOnly flag on session cookies. |
|  | 🟢 high | V-222576 | APSC-DV-002220 | Not_Applicable | web_based=no | The application must set the secure flag on session cookies. |
|  | 🟢 high | V-222577 | APSC-DV-002230 | Not_Applicable | web_based=no | The application must not expose session IDs. |
|  | 🟢 high | V-222578 | APSC-DV-002240 | Not_Applicable | web_based=no | The application must destroy the session ID value and/or cookie on logoff or browser close… |
|  | 🟢 high | V-222579 | APSC-DV-002250 | Not_Applicable | web_based=no | Applications must use system-generated session identifiers that protect against session fi… |
|  | 🟢 high | V-222580 | APSC-DV-002260 | Not_Applicable | web_based=no | Applications must validate session identifiers. |
|  | 🟢 high | V-222581 | APSC-DV-002270 | Not_Applicable | web_based=no | Applications must not use URL embedded session IDs. |
|  | 🟢 high | V-222582 | APSC-DV-002280 | Not_Applicable | web_based=no | The application must not re-use or recycle session IDs. |
|  | 🟢 high | V-222583 | APSC-DV-002290 | Not_Applicable | web_based=no | The application must generate a unique session identifier using a FIPS 140-2/140-3 approve… |
|  | 🟢 high | V-222593 | APSC-DV-002390 | Not_Applicable | processes_xml=no | XML-based applications must mitigate DoS attacks by using XML filters, parser options, or … |
|  | 🟢 high | V-222602 | APSC-DV-002490 | Not_Applicable | web_based=no | The application must protect from Cross-Site Scripting (XSS) vulnerabilities. |
|  | 🟢 high | V-222603 | APSC-DV-002500 | Not_Applicable | web_based=no | The application must protect from Cross-Site Request Forgery (CSRF) vulnerabilities. |
|  | 🟢 high | V-222607 | APSC-DV-002540 | Not_Applicable | uses_database=no | The application must not be vulnerable to SQL Injection. |
|  | 🟢 high | V-222608 | APSC-DV-002550 | Not_Applicable | processes_xml=no | The application must not be vulnerable to XML-oriented attacks. |
|  | 🟢 high | V-222643 | APSC-DV-003120 | Not_Applicable | data_classification=Unclassified (no CUI) | The application must have the capability to mark sensitive/classified output when required… |
|  | 🟢 high | V-222664 | APSC-DV-003290 | Not_Applicable | data_classification=Unclassified (no CUI) | If the application contains classified data, a Security Classification Guide must exist co… |
|  | 🟢 high | V-222666 | APSC-DV-003310 | Not_Applicable | uses_database=no | Production database exports must have database administration credentials and sensitive da… |

## 2. Comment-only (status stays Not_Reviewed — verify the guidance is apt)

| ✔ | V-ID | Rule | Requirement |
|---|------|------|-------------|
|  | V-222423 | APSC-DV-000440 | Application data protection requirements must be identified and documented. |
|  | V-222506 | APSC-DV-001340 | The application must back up audit records at least every seven days onto a different syst… |
|  | V-222510 | APSC-DV-001390 | The application must prohibit user installation of software without explicit privileged st… |
|  | V-222511 | APSC-DV-001410 | The application must enforce access restrictions associated with changes to application co… |
|  | V-222512 | APSC-DV-001420 | The application must audit who makes configuration changes to the application. |
|  | V-222513 | APSC-DV-001430 | The application must have the capability to prevent the installation of patches, service p… |
|  | V-222514 | APSC-DV-001440 | The applications must limit privileges to change the software resident within software lib… |
|  | V-222518 | APSC-DV-001500 | The application must be configured to disable non-essential capabilities. |
|  | V-222519 | APSC-DV-001510 | The application must be configured to use only functions, ports, and protocols permitted t… |
|  | V-222567 | APSC-DV-001995 | The application must not be vulnerable to race conditions. |
|  | V-222570 | APSC-DV-002020 | The application must utilize FIPS-validated cryptographic modules when signing application… |
|  | V-222571 | APSC-DV-002030 | The application must utilize FIPS-validated cryptographic modules when generating cryptogr… |
|  | V-222572 | APSC-DV-002040 | The application must utilize FIPS-validated cryptographic modules when protecting unclassi… |
|  | V-222584 | APSC-DV-002300 | The application must only allow the use of DoD-approved certificate authorities for verifi… |
|  | V-222585 | APSC-DV-002310 | The application must fail to a secure state if system initialization fails, shutdown fails… |
|  | V-222586 | APSC-DV-002320 | In the event of a system failure, applications must preserve any information necessary to … |
|  | V-222587 | APSC-DV-002330 | The application must protect the confidentiality and integrity of stored information when … |
|  | V-222588 | APSC-DV-002340 | The application must implement approved cryptographic mechanisms to prevent unauthorized m… |
|  | V-222589 | APSC-DV-002350 | The application must use appropriate cryptography in order to protect stored DOD informati… |
|  | V-222596 | APSC-DV-002440 | The application must protect the confidentiality and integrity of transmitted information. |
|  | V-222597 | APSC-DV-002450 | The application must implement cryptographic mechanisms to prevent unauthorized disclosure… |
|  | V-222598 | APSC-DV-002460 | The application must maintain the confidentiality and integrity of information during prep… |
|  | V-222599 | APSC-DV-002470 | The application must maintain the confidentiality and integrity of information during rece… |
|  | V-222604 | APSC-DV-002510 | The application must protect from command injection. |
|  | V-222605 | APSC-DV-002520 | The application must protect from canonical representation vulnerabilities. |
|  | V-222606 | APSC-DV-002530 | The application must validate all input. |
|  | V-222609 | APSC-DV-002560 | The application must not be subject to input handling vulnerabilities. |
|  | V-222610 | APSC-DV-002570 | The application must generate error messages that provide information necessary for correc… |
|  | V-222611 | APSC-DV-002580 | The application must reveal error messages only to the ISSO, ISSM, or SA. |
|  | V-222612 | APSC-DV-002590 | The application must not be vulnerable to overflow attacks. |
|  | V-222613 | APSC-DV-002610 | The application must remove organization-defined software components after updated version… |
|  | V-222614 | APSC-DV-002630 | Security-relevant software updates and patches must be kept up to date. |
|  | V-222621 | APSC-DV-002900 | The ISSO must ensure application audit trails are retained for at least 30 months (12 mont… |
|  | V-222622 | APSC-DV-002910 | The ISSO must review audit trails periodically based on system documentation recommendatio… |
|  | V-222623 | APSC-DV-002920 | The ISSO must report all suspected violations of IA policies in accordance with DoD inform… |
|  | V-222627 | APSC-DV-002970 | The ISSO must ensure if a DoD STIG or NSA guide is not available, a third-party product wi… |
|  | V-222628 | APSC-DV-002980 | New IP addresses, data services, and associated ports used by the application must be subm… |
|  | V-222629 | APSC-DV-002990 | The application must be registered with the DoD Ports and Protocols Database. |
|  | V-222630 | APSC-DV-002995 | The Configuration Management (CM) repository must be properly patched and STIG compliant. |
|  | V-222631 | APSC-DV-003000 | Access privileges to the Configuration Management (CM) repository must be reviewed every t… |
|  | V-222636 | APSC-DV-003050 | A contingency plan must exist in accordance with DOD policy based on the application's ava… |
|  | V-222637 | APSC-DV-003060 | Recovery procedures and technical system features must exist so recovery is performed in a… |
|  | V-222638 | APSC-DV-003070 | Data backup must be performed at required intervals in accordance with DoD policy. |
|  | V-222639 | APSC-DV-003080 | Back-up copies of the application software or source code must be stored in a fire-rated c… |
|  | V-222640 | APSC-DV-003090 | Procedures must be in place to assure the appropriate physical and technical protection of… |
|  | V-222641 | APSC-DV-003100 | The application must use encryption to implement key exchange and authenticate endpoints p… |
|  | V-222642 | APSC-DV-003110 | The application must not contain embedded authentication data. |
|  | V-222651 | APSC-DV-003200 | The changes to the application must be assessed for IA and accreditation impact prior to i… |
|  | V-222656 | APSC-DV-003235 | The application must not be subject to error handling vulnerabilities. |
|  | V-222658 | APSC-DV-003240 | All products must be supported by the vendor or the development team. |
|  | V-222659 | APSC-DV-003250 | The application must be decommissioned when maintenance or support is no longer available. |
|  | V-222660 | APSC-DV-003260 | Procedures must be in place to notify users when an application is decommissioned. |
|  | V-222663 | APSC-DV-003285 | An Application Configuration Guide must be created and included with the application. |
|  | V-222669 | APSC-DV-003340 | At least one application administrator must be registered to receive update notifications,… |
|  | V-222670 | APSC-DV-003345 | The application must provide notifications or alerts when product update and security rela… |

## 3. Left at Not_Reviewed (wizard does not touch — confirm none should be auto-gated)

Mostly the always-applicable audit-record-generation cluster (V-222441–222509) plus baseline identity / security-function requirements.

| V-ID | Rule | Requirement |
|------|------|-------------|
| V-222389 | APSC-DV-000070 | The application must automatically terminate the non-privileged user session and log off n… |
| V-222390 | APSC-DV-000080 | The application must automatically terminate the admin user session and log off admin user… |
| V-222391 | APSC-DV-000090 | Applications requiring user access authentication must provide a logoff capability for use… |
| V-222392 | APSC-DV-000100 | The application must display an explicit logoff message to users indicating the reliable t… |
| V-222393 | APSC-DV-000110 | The application must associate organization-defined types of security attributes having or… |
| V-222394 | APSC-DV-000120 | The application must associate organization-defined types of security attributes having or… |
| V-222395 | APSC-DV-000130 | The application must associate organization-defined types of security attributes having or… |
| V-222424 | APSC-DV-000450 | The application must utilize organization-defined data mining detection techniques for org… |
| V-222427 | APSC-DV-000480 | The application must enforce approved authorizations for controlling the flow of informati… |
| V-222428 | APSC-DV-000490 | The application must enforce approved authorizations for controlling the flow of informati… |
| V-222431 | APSC-DV-000520 | The application must audit the execution of privileged functions. |
| V-222438 | APSC-DV-000590 | The application must protect against an individual (or process acting on behalf of an indi… |
| V-222441 | APSC-DV-000620 | The application must provide audit record generation capability for the creation of sessio… |
| V-222442 | APSC-DV-000630 | The application must provide audit record generation capability for the destruction of ses… |
| V-222443 | APSC-DV-000640 | The application must provide audit record generation capability for the renewal of session… |
| V-222444 | APSC-DV-000650 | The application must not write sensitive data into the application logs. |
| V-222445 | APSC-DV-000660 | The application must provide audit record generation capability for session timeouts. |
| V-222446 | APSC-DV-000670 | The application must record a time stamp indicating when the event occurred. |
| V-222447 | APSC-DV-000680 | The application must provide audit record generation capability for HTTP headers including… |
| V-222448 | APSC-DV-000690 | The application must provide audit record generation capability for connecting system IP a… |
| V-222449 | APSC-DV-000700 | The application must record the username or user ID of the user associated with the event. |
| V-222450 | APSC-DV-000710 | The application must generate audit records when successful/unsuccessful attempts to grant… |
| V-222451 | APSC-DV-000720 | The application must generate audit records when successful/unsuccessful attempts to acces… |
| V-222452 | APSC-DV-000730 | The application must generate audit records when successful/unsuccessful attempts to acces… |
| V-222454 | APSC-DV-000750 | The application must generate audit records when successful/unsuccessful attempts to modif… |
| V-222455 | APSC-DV-000760 | The application must generate audit records when successful/unsuccessful attempts to modif… |
| V-222456 | APSC-DV-000770 | The application must generate audit records when successful/unsuccessful attempts to modif… |
| V-222458 | APSC-DV-000790 | The application must generate audit records when successful/unsuccessful attempts to delet… |
| V-222459 | APSC-DV-000800 | The application must generate audit records when successful/unsuccessful attempts to delet… |
| V-222463 | APSC-DV-000840 | The application must generate audit records for privileged activities or other system-leve… |
| V-222464 | APSC-DV-000850 | The application must generate audit records showing starting and ending time for user acce… |
| V-222465 | APSC-DV-000860 | The application must generate audit records when successful/unsuccessful accesses to objec… |
| V-222466 | APSC-DV-000870 | The application must generate audit records for all direct access to the information syste… |
| V-222468 | APSC-DV-000910 | The application must initiate session auditing upon startup. |
| V-222469 | APSC-DV-000940 | The application must log application shutdown events. |
| V-222470 | APSC-DV-000950 | The application must log destination IP addresses. |
| V-222471 | APSC-DV-000960 | The application must log user actions involving access to data. |
| V-222472 | APSC-DV-000970 | The application must log user actions involving changes to data. |
| V-222473 | APSC-DV-000980 | The application must produce audit records containing information to establish when (date … |
| V-222474 | APSC-DV-000990 | The application must produce audit records containing enough information to establish whic… |
| V-222475 | APSC-DV-001000 | When using centralized logging; the application must include a unique identifier in order … |
| V-222476 | APSC-DV-001010 | The application must produce audit records that contain information to establish the outco… |
| V-222477 | APSC-DV-001020 | The application must generate audit records containing information that establishes the id… |
| V-222480 | APSC-DV-001050 | The application must provide centralized management and configuration of the content to be… |
| V-222481 | APSC-DV-001070 | The application must off-load audit records onto a different system or media than the syst… |
| V-222482 | APSC-DV-001080 | The application must be configured to write application logs to a centralized log reposito… |
| V-222483 | APSC-DV-001090 | The application must provide an immediate warning to the SA and ISSO (at a minimum) when a… |
| V-222484 | APSC-DV-001100 | Applications categorized as having a moderate or high impact must provide an immediate rea… |
| V-222485 | APSC-DV-001110 | The application must alert the ISSO and SA (at a minimum) in the event of an audit process… |
| V-222486 | APSC-DV-001120 | The application must shut down by default upon audit failure (unless availability is an ov… |
| V-222488 | APSC-DV-001140 | The application must provide the capability to filter audit records for events of interest… |
| V-222497 | APSC-DV-001250 | The applications must use internal system clocks to generate time stamps for audit records… |
| V-222498 | APSC-DV-001260 | The application must record time stamps for audit records that can be mapped to Coordinate… |
| V-222499 | APSC-DV-001270 | The application must record time stamps for audit records that meet a granularity of one s… |
| V-222500 | APSC-DV-001280 | The application must protect audit information from any type of unauthorized read access. |
| V-222501 | APSC-DV-001290 | The application must protect audit information from unauthorized modification. |
| V-222502 | APSC-DV-001300 | The application must protect audit information from unauthorized deletion. |
| V-222503 | APSC-DV-001310 | The application must protect audit tools from unauthorized access. |
| V-222504 | APSC-DV-001320 | The application must protect audit tools from unauthorized modification. |
| V-222505 | APSC-DV-001330 | The application must protect audit tools from unauthorized deletion. |
| V-222507 | APSC-DV-001350 | The application must use cryptographic mechanisms to protect the integrity of audit inform… |
| V-222508 | APSC-DV-001360 | Application audit tools must be cryptographically hashed. |
| V-222509 | APSC-DV-001370 | The integrity of the audit tools must be validated by checking the files for changes in th… |
| V-222516 | APSC-DV-001480 | The application must prevent program execution in accordance with organization-defined pol… |
| V-222517 | APSC-DV-001490 | The application must employ a deny-all, permit-by-exception (whitelist) policy to allow th… |
| V-222520 | APSC-DV-001520 | The application must require users to reauthenticate when organization-defined circumstanc… |
| V-222521 | APSC-DV-001530 | The application must require devices to reauthenticate when organization-defined circumsta… |
| V-222522 | APSC-DV-001540 | The application must uniquely identify and authenticate organizational users (or processes… |
| V-222532 | APSC-DV-001640 | The application must utilize mutual authentication when endpoint device non-repudiation pr… |
| V-222533 | APSC-DV-001650 | The application must authenticate all network connected endpoint devices before establishi… |
| V-222535 | APSC-DV-001670 | The application must disable device identifiers after 35 days of inactivity unless a crypt… |
| V-222555 | APSC-DV-001860 | The application must use mechanisms meeting the requirements of applicable federal laws, E… |
| V-222556 | APSC-DV-001870 | The application must uniquely identify and authenticate non-organizational users (or proce… |
| V-222568 | APSC-DV-002000 | The application must terminate all network connections associated with a communications se… |
| V-222574 | APSC-DV-002150 | The application user interface must be either physically or logically separated from data … |
| V-222590 | APSC-DV-002360 | The application must isolate security functions from non-security functions. |
| V-222591 | APSC-DV-002370 | The application must maintain a separate execution domain for each executing process. |
| V-222592 | APSC-DV-002380 | Applications must prevent unauthorized and unintended information transfer via shared syst… |
| V-222594 | APSC-DV-002400 | The application must restrict the ability to launch Denial of Service (DoS) attacks agains… |
| V-222600 | APSC-DV-002480 | The application must not disclose unnecessary information to users. |
| V-222601 | APSC-DV-002485 | The application must not store sensitive information in hidden fields. |
| V-222615 | APSC-DV-002760 | The application performing organization-defined security functions must verify correct ope… |
| V-222616 | APSC-DV-002770 | The application must perform verification of the correct operation of security functions: … |
| V-222617 | APSC-DV-002780 | The application must notify the ISSO and ISSM of failed security verification tests. |
| V-222626 | APSC-DV-002960 | The designer must ensure the application does not store configuration and control files in… |
| V-222634 | APSC-DV-003030 | The application services and interfaces must be compatible with and ready for IPv6 network… |
| V-222635 | APSC-DV-003040 | The application must not be hosted on a general purpose machine if the application is desi… |
| V-222662 | APSC-DV-003280 | Default passwords must be changed. |
| V-222667 | APSC-DV-003320 | Protections against DoS attacks must be implemented. |
| V-222668 | APSC-DV-003330 | The system must alert an administrator when low resource conditions are encountered. |
