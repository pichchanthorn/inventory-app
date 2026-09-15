# ADR-006: Cash & Money Management, Not a Wallet

## Status

Accepted

## Context

PCTN currently records cash-related data only as fields on individual transactions: `stock_transactions.cash_received` for a POS cash sale, and `customer_debts`/`customer_debt_payments` for credit-sale receivables. There is no concept today of a cash position, a bank account, or an owner-drawings ledger. As the product grows toward a commercial offering, a coherent way to track the shop's actual cash position (till cash, bank balance, owner deposits/withdrawals, expenses) becomes a natural need. The term "wallet" was explicitly considered and rejected as framing, since it implies a consumer-style stored-value account rather than a business cash-position ledger.

## Decision

A future **Cash & Money Management** module (`ARCHITECTURE.md` §11) is defined conceptually: one or more Cash Accounts (Shop Cash, Bank, Other Accounts), each with a balance that is **always derived from its transaction history** —

```
Opening Balance + Sales + Debt Payments + Owner Deposits
  - Expenses - Owner Withdrawals ± Transfers = Current Balance
```

— using the same "store the line, derive/guard the aggregate" principle already proven by `products.current_stock` and `customer_debts.balance` in the existing schema. A Cash Account balance must never be directly, manually editable by any future UI or API — every change must be the result of recording one of the defined transaction types (`SALE`, `DEBT_PAYMENT`, `EXPENSE`, `OWNER_DEPOSIT`, `OWNER_WITHDRAWAL`, `TRANSFER`, `OPENING_BALANCE`).

**No table, migration, or code for this module is created by this decision.** This ADR fixes the conceptual model so that whenever this module is actually built, it is built as a transaction-derived ledger from the start, not as an editable-balance field that is retrofitted with a ledger later.

## Consequences

- When Cash & Money is eventually implemented, `SALE` and `DEBT_PAYMENT` transactions are expected to be produced as a side effect of the existing Sales/Debt modules' own transactions (`ARCHITECTURE.md` §10) — Cash & Money consumes those events rather than duplicating them as a second source of truth.
- `EXPENSE` introduces a genuinely new event source with no current analog in the schema — its own design (categories, approval, recurring vs. one-off) is explicitly not addressed by this decision and remains open.
- The "never manually editable" rule is a hard constraint any future implementation must satisfy structurally (e.g. via a generated/derived-only balance column or a strictly transaction-mediated update path), the same way `customer_debts.balance` is a `GENERATED ALWAYS` column today rather than a field a form can set directly.

## Alternatives Considered

- **A simple editable "current cash balance" field per account:** rejected — this reintroduces exactly the "forgot to keep the cache in sync" bug class the existing schema has already eliminated for stock and debt balances via generated/guarded-transactional patterns; a manually-set balance also has no audit trail explaining how it arrived at its value.
- **A consumer "wallet" model (stored value, transfers between arbitrary parties):** rejected — explicitly the wrong framing per the task's own instruction; this is a business cash-position ledger, not a stored-value account system.
