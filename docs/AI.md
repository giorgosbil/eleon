# Email assistant and enhanced search

Implemented 2026-09-22, explicitly authorized by the product owner.

## Using the assistant

- Mail: **Ask AI** searches the selected mailbox. Ask a question; optional Search
  terms accept normal mail search syntax, including `filename:quote.pdf`,
  `from:"George Brown"`, and `case:CASE-00003`.
- Cases: **Ask AI** pre-fills the case filter, scoped to the selected thread's
  mailbox (the first thread's mailbox when no individual thread is selected).
- Replies/reply-all/forwards: **Draft with AI** uses the target email plus five
  chronologically earlier messages in that thread, or all earlier messages when
  fewer exist. Later messages are excluded.
- Generated text is shown for review. **Insert suggestion into reply** appends to
  existing typed content, without replacing it, its signature, or quoted history.
  The user still presses Send separately.
- The assistant provides email-source links. It sees excerpts, not the entire
  mailbox or attachment contents. It cannot perform web research or mailbox actions.

## Provider and credentials

Uses Anthropic's Messages API with `claude-haiku-4-5-20251001`.
The key comes from `ECW_CLAUDE_API_KEY`, or `claude.api_key` in the existing server
configuration. No secret is sent to the browser, displayed in Settings, or logged.
There is no fallback that silently uploads email to a free provider.

Free API availability is not equivalent to suitable business-email data terms.
Google's free Gemini tier lists product-improvement use of submitted data in its
[pricing/data-use table](https://ai.google.dev/gemini-api/docs/pricing).
The owner authorized Claude as fallback; its existing server key is used.

### Your own AI (OpenAI-compatible endpoint)

**Settings → Email assistant** lets an administrator pick, per workspace:

- **Built in — Claude**: the server key above. The workspace may lower the
  server's daily allowance, never raise it.
- **Your own AI**: any OpenAI-compatible `/chat/completions` endpoint (Ollama,
  LM Studio, LiteLLM, OpenAI/Azure, Mistral, Groq, a company gateway). Base URL,
  model and an optional key; the key is stored AES-256-GCM encrypted
  (`organizations.ai_json`) and never shown again. The daily cap defaults to 200.
  Only http/https, no credentials or query string in the URL, redirects are not
  followed, 120 s timeout. `<think>` blocks from reasoning models are removed.
  **Save and test connection** sends one synthetic sentence, never email.

The product does not bundle or enable any free-token router (e.g. OmniRoute):
customer email must not go to providers whose terms allow training on it, and
that project has had a critical unauthenticated RCE (CVE-2026-88062). An
administrator can still point the endpoint at one, at their own risk.

## The assistant as an agent (Ask AI)

Questions in **Ask AI** go to `lib/ai_agent.php` (API action `ai_agent`, streamed as
NDJSON so the panel shows each step live). The model chooses read-only tools from
`lib/ai_tools.php`; Claude and OpenAI-compatible endpoints get the **same tools**:

| Tool | Use |
|---|---|
| `search_emails` | sender, recipient, subject, any/all words, dates, sent/received, attachments, folder, case |
| `count_emails` | exact totals, per month / year / sender / domain / folder |
| `read_email` | one email in full |
| `list_files` | attachments of matching emails (shown as download cards) |
| `find_amounts` | money in the email text (HTML too), likely total per email, summed per currency, each invoice number counted once |
| `get_case`, `list_cases` | case facts, latest emails, notes; cases by status / overdue |

Scope is the user's own: their mailboxes plus conversations of cases they can see
(as Contacts). Deleted and Junk are left out unless a folder is named.

Guards for small local models: up to 6 provider calls per question (each counts
against the daily allowance), 4 tools per step, results cut at 9 KB; an answer
given without looking anything up is sent back once ("use the tools, never make up
numbers"); an empty answer is asked for once more; the answer language is stated
outright; lists the model jams into one string are split. A model without tool
support falls back to the one-search answer. Files of a named case are answered
straight from the database (no AI request). The last 3 questions and answers are
sent along, so follow-ups ("how much did I pay them?") work.

Ollama: set `OLLAMA_CONTEXT_LENGTH=16384` (Windows: user environment variable,
then restart Ollama). The default 4096 silently drops part of the request.

Not yet: amounts that exist only inside PDF attachments.

## Microsoft 365 Copilot and other AI apps (MCP)

`web/mcp.php` is an MCP server (streamable HTTP, JSON answers) with the **same
read-only tools** as Ask AI and the same per-user scope and feature rules
(package → Feature access → My view must allow "AI assistant").

- **Copilot**: a declarative agent with an MCP plugin. Sign-in is Microsoft Entra
  SSO: the token's tenant, audience, expiry and RS256 signature (Microsoft JWKS,
  cached 12 h) are checked, then the person is found by their sign-in address or
  their connected mailbox address. **Settings → Copilot & AI apps** has the steps
  and downloads the app package (manifest 1.19, declarative agent 1.6, plugin
  2.4 `RemoteMCPServer` + `OAuthPluginVault`). Needs a public HTTPS address and a
  Copilot licence per user.
- **Other MCP apps** (Claude, VS Code, Cursor…): a personal access token
  (`Authorization: Bearer ecw_…`), stored as SHA-256 only, removable any time.
- 120 tool calls per user per minute. Tool results carry links back into the app.

## Cost controls

- One Claude request per explicit click; no background AI processing or retries.
- 20 provider requests per organization per UTC day by default, atomically reserved
  before the call through the existing rate-limit table. Provider failures consume
  a slot because a timeout may still incur provider usage.
- 3 assistant attempts per user per minute.
- 1,000 output tokens, no extended thinking, no tools.
- Maximum serialized provider request: 22,000 UTF-8 bytes.
- Up to 8 matched email excerpts for research; up to 6 for reply generation.
- Each body excerpt is capped at 1,800 bytes, with explicit truncation markers.
- Optional `ecw_ai.daily_requests` in server configuration adjusts the daily
  allowance between 1 and 100. **Settings → Email assistant** shows current usage.

The daily allowance is a request cap, not an invoice-level dollar guarantee.
Set an additional provider account spending limit if required. Current rates are
published by [Anthropic](https://platform.claude.com/docs/en/about-claude/pricing).

## Search

Bare terms can match message content, participant names/addresses, and filenames.
`filename:` and `attachment:` restrict a term to attachment names. Quoted filenames
and punctuation are supported, with literal SQL wildcard handling. Existing
sender/recipient, subject, case, folder, date, read-state, and attachment filters remain.
Search covers locally synchronized/indexed metadata and downloaded body text;
it does not search inside attached PDFs/CAD files and is not full Outlook parity.

## Verification

- `php tests/ai_search_test.php`: scoped retrieval, target plus five earlier
  messages, no later-message leakage, one bounded provider call, daily quota,
  and filename/name search. Transactional fixtures and a mocked Claude transport.
- `node tests/ai_assistant_browser.cjs`: search, sources, plain-text safety,
  explicit insertion preserving an existing answer, no automatic send, mobile layout.
- `php tests/render_test.php`: assistant POST/CSRF guards and settings rendering.
- A live connection smoke test succeeded using 19 input and 4 output tokens.
  It sent only synthetic text, no actual email content.
