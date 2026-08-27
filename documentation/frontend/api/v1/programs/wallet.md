## Wallets

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/{program}/wallets` | Get program wallet |
| POST | `/wallets/{wallet}/deposit` | Start a deposit |
| POST | `/wallets/{wallet}/deposit-status` | Update deposit status |

Write responses use the standard success/error envelope. Wallet operations require the program owner.
