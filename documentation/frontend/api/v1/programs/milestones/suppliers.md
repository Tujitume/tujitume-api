## Suppliers and Budget Items

All routes are under `/api/v1/programs` and require Sanctum authentication. The applicant and owning program organization can view assignments. The owner can manage the directory; the applicant may add assignments/budget items only when allowed by the hybrid template and while `funding_setup_status` is `awaiting_applicant_revision`. Funding setup locks these edits after activation.

### Supplier directory and milestone assignments

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/supplier-directory` | List the current user's suppliers; supports `search`, `supplier_type`, `is_active` and pagination |
| POST | `/supplier-directory` | Add a supplier to the directory |
| GET | `/supplier-directory/{supplier}` | Get a supplier owned by the current user |
| PUT/PATCH | `/supplier-directory/{supplier}` | Update supplier details |
| DELETE | `/supplier-directory/{supplier}` | Delete a directory entry |
| POST | `/supplier-directory/{supplierId}/activate` or `/deactivate` | Toggle supplier availability |
| GET | `/supplier-directory/{supplierId}/assigned-milestones` | Paginated assignments; optional `per_page` |
| GET | `/supplier-directory/providers` | Search/list provider accounts for assignment |
| POST | `/milestones/{milestone}/assign-suppliers` | Assign a directory supplier to a milestone |
| GET | `/milestones/{milestone}/suppliers` | List milestone assignments |
| DELETE | `/suppliers/{supplier}` | Remove a milestone assignment |

Supplier creation requires `legal_name` (string, max 255), `phone` (string, max 30), `email` (valid email, max 191), and `payment_method` (`mpesa_mobile`, `mpesa_lipr`, `mpesa_paybill`, `mpesa_till`, `bank_transfer`, `other`). Optional fields include `contact_person`, `supplier_type`, `user_id` (existing account ID), payment details, notes and address fields. Paybill requires `mpesa_paybill_number` and `mpesa_paybill_account`; till requires `mpesa_till_number` and `mpesa_account_reference`; bank transfer requires `bank_name` and `bank_account_number`; LIPR requires `lipr_wallet`; mobile M-Pesa requires `lipr_mobile_number`. Duplicate directory emails return `422`.

Milestone assignment body:

```json
{"supplier_id":18,"payment_route":"direct_to_supplier","quoted_amount":25000,"assignment_type":"approved"}
```

`supplier_id`, `payment_route`, and `quoted_amount` are required. `payment_route` accepts `direct_to_supplier`, `split`, or `direct_to_applicant`; the quote must be non-negative and no greater than the milestone amount. `assignment_type` is optional: `primary`, `approved`, or `preferred`. A deactivated supplier, duplicate assignment, or forbidden lifecycle state returns `422`/`403` as applicable. Success is `201` with the assignment in `data`; assignment rows include the payment route.

### Budget items

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/milestones/{milestone}/budget-items` | List items and allocation summary |
| POST | `/milestones/{milestone}/budget-items` | Add an item |
| DELETE | `/milestones/{milestone}/budget-items/{budgetItemId}` | Delete an item belonging to this milestone |

Create body:

```json
{"item_description":"Water pump","unit_cost":5000,"quantity":2,"supplier_id":7,"purpose_type":"capex"}
```

`item_description` (string, max 500), `unit_cost` (number >= 0), and `quantity` (number >= 0.01) are required. `supplier_id` is optional and, when supplied, must be a **milestone supplier assignment ID for this same milestone**, not a supplier-directory ID. `purpose_type` is optional: `capex`, `opex`, `services`, or `mixed`. The server calculates `total_cost`; the total of all items cannot exceed the milestone amount. The response is `201` with the item in `data`, including `added_by` (`program_owner` or `applicant`).

The list response has `budget_items`, `summary` (`milestone_amount`, `total_allocated`, `remaining_amount`) and `status` (`budget_matches`, `suppliers_exist`, `mprv_ready`, `current_status`). A budget item delete is scoped to its milestone and returns `404` if it belongs elsewhere or does not exist.
