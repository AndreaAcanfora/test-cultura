# Test di Cultura

An adaptive general-knowledge quiz over **60 Italian school subjects** and **2,400 questions**.
Vanilla JS + Tailwind frontend, PHP backend, open-answer grading via **Claude Haiku 4.5**.

> The quiz content is in Italian (it covers the Italian school curriculum), but the
> codebase and this documentation are language-agnostic.

## Features

- **Adaptive mode** — starts easy and recalibrates every 5 questions: average ≥ 70 moves you
  up a level, < 40 moves you down, in between keeps you where you are.
- **Manual mode** — pick the difficulty bands yourself (Easy 5–25 / Medium 26–50 /
  Hard 51–75 / Expert 76–100, multi-select).
- **Specificity control (20–100)** — at 20 you only get general-culture subjects; at 100 you
  also get hyper-specialized ones.
- **Exclude up to 3 subjects** from the pool of 60.
- **20 / 25 / 30** questions per run.
- **Multiple-choice** (graded locally, no API call) or **open-ended** answers (graded by Claude).
- Local, file-based **leaderboard** with separate rankings for adaptive and manual runs.

## Architecture

```
test-cultura/
├── index.html              # Single-page app (Tailwind)
├── app.js                  # Frontend logic (quiz flow, pagination, fetch)
├── api/
│   ├── get-batch.php       # Serves 5 questions server-side based on quiz config
│   ├── grade-batch.php     # Grading: local for multiple-choice, Anthropic API for open answers
│   ├── save-result.php     # Appends a run to the leaderboard (file lock, capped at 500 entries)
│   └── leaderboard.php     # Returns the top 5 per mode
├── data/
│   ├── materie.json        # Dataset: 60 subjects × 40 questions
│   └── manifest.json       # Subject metadata (specificity, difficulty range)
├── .config.example.php     # Config template — copy to .config.php and add your API key
├── .htaccess               # Denies dot-files, disables listing, sets security headers
└── README.md
```

## Setup

Requirements: PHP 8+ with the cURL extension. An Anthropic API key is only needed for
open-ended grading — multiple-choice mode works offline.

```bash
# 1. Configure
cp .config.example.php .config.php
# edit .config.php and set ANTHROPIC_API_KEY

# 2. Run
php -S localhost:8000
```

Open `http://localhost:8000`.

To deploy, upload the folder to any PHP-capable host. The included `.htaccess` blocks web
access to `.config.php` and other dot-files — verify it is honored:

```bash
curl -i https://your-host/test-cultura/.config.php   # must return 403
```

## How grading works

`api/grade-batch.php` grades **open-ended** answers with Claude Haiku 4.5:

- `temperature: 0` (deterministic)
- `tool_choice` forced to a `submit_grade` tool → structured JSON output `{score, motivazione}`
- System prompt with rubric + few-shot examples, kept warm with `cache_control: ephemeral`
  (prompt caching)
- Up to 5 answers graded in parallel via `curl_multi_exec`

Multiple-choice answers are compared server-side with no API call.

## Scoring

The final score is a difficulty-weighted average:

```
final_score = Σ(score_i × difficulty_i) / Σ(difficulty_i)
```

A perfect answer on a hard question (difficulty 90) is worth more than a perfect answer on an
easy one (difficulty 10).

## Security notes

- The API key lives only in `.config.php`, guarded by `if (!defined('ENTRY_POINT')) die();`
  and never shipped to the client.
- `.config.php` is git-ignored; only the `.config.example.php` template is committed.
- `.htaccess` denies any file or directory starting with a dot and disables directory listing.
- `data/leaderboard.json` is user-generated at runtime and git-ignored.

## License

[MIT](LICENSE) © 2026 Andrea Acanfora
