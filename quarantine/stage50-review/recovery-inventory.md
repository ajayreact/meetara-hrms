# Stage 50 recovery inventory

No missing historical migration is a verified original. None were copied into `database/migrations`. Existing quarantine files were not overwritten.

| Migration | Status | Source | Bytes | SHA-256 | `up()` / `down()` |
|---|---|---|---|---|---|
| `2026_10_19_023112_create_permission_tables` | `partial_evidence_only` | Spatie 8.3.0 stub at `D:\wamp64\www\VSP-CRM\vendor\spatie\laravel-permission\database\migrations\create_permission_tables.php.stub`. Composer lock there records version 8.3.0, git `60e8ed5b2fbf043c2264433fc2680c76b8b66aa6`. | 6442 | `bd097ba10f37a131a9a8885ed4723387521a2f222f636b37b911e7b9ed34eec2` | The stub has both methods. The published Meetara file was saved only through line 80. |
| `2026_10_19_100000_create_hrms_tables` | `recovered_but_unverified` | `quarantine/stage44-historical-migration-payloads/2026_10_19_100000_create_hrms_tables.php`. Transcript Write, chat f0697b32 line 47. | 19262 | `4ab8b5a55312240eea8bcfbfc5257ed1dbf62827ce2f8235466ad48f7bff7eda` | Both methods are in the payload. |
| `2026_10_19_120000_create_remaining_phase_tables` | `recovered_but_unverified` | Same directory. Transcript Write, line 114. | 11046 | `106fa2cfce41fce713fac720edbb28cc9d34934b446f9be7337b5bc084470770` | Both methods are in the payload. |
| `2026_10_19_130000_add_holiday_calendars` | `recovered_but_unverified` | Same directory. Transcript Write, line 162. | 842 | `b6f58cf6148d2605c0df145ad8e6e3d652f46e9b871d57418e3723c352037c07` | Both methods are in the payload. |
| `2026_10_19_140000_add_office_wifi_and_wfh` | `recovered_but_unverified` | Same directory. Transcript Write, line 190. | 1630 | `797f4d2aa1d9a2a8a79213edd49fe93f0e742ab1a1e489cbedad4cdd8ab1dee1` | Both methods are in the payload. |
| `2026_10_19_140000_add_payroll_day_counts` | `recovered_but_unverified` | Same directory. Transcript Write, line 424. | 949 | `50355fcf9828e2f16cee0bf86f4b4d167423b1d03f26ffcaea929c2934e6d089` | Both methods are in the payload. |
| `2026_10_19_160000_add_profile_details_and_tickets` | `recovered_but_unverified` | Same directory. Transcript Write, line 512. | 1424 | `395625052dc84659d62a769d531369d493889c2c61487a871ea25832029e180f` | Both methods are in the payload. |

Stage 50 rehashed the six payloads. The hashes match Stage 43 and Stage 44. Recovery method for those six: decode the single transcript Write and store the bytes. No second original file was found. No `StrReplace` of those six paths was found after the Write.

The permission stub was not copied into this project. `down()` of the stub drops the five permission tables. That method was not in the saved 80-line excerpt.

Schema comparison remains the Stage 46 and Stage 48 result: the six payloads' created columns that were checked against `database/schema/mysql-schema.sql` and `D:\wamp64\backups\meetara_hrms-before-office-networks.sql` match the final tables after later on-disk migrations are taken into account. A schema match is not byte-for-byte proof of the original file.

Unresolved for every row: the file that was actually migrated is gone, and the current `0001` users migration no longer contains the five company columns the `100000` payload expects.
