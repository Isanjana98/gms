        :root {
            --ink: #15161a;
            --muted: #64666f;
            --dim: #8b8d96;
            --line: #e3e4e8;
            --bg: #f6f7f9;
            --card: #ffffff;
            --accent: #e11d2e;
            --accent-dark: #b4121f;
            --good: #0f9d6b;
            --radius: 14px;
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            padding-bottom: env(safe-area-inset-bottom);
        }

        .wrap { max-width: 640px; margin: 0 auto; padding: 0 16px 48px; }

        header {
            background: var(--ink);
            color: #fff;
            padding: 22px 16px 26px;
            margin-bottom: -14px;
        }
        header .inner { max-width: 640px; margin: 0 auto; }
        .gym { font-size: 13px; letter-spacing: .14em; text-transform: uppercase; color: #a7a9b4; }
        h1 { font-size: 23px; margin: 6px 0 0; letter-spacing: -.02em; }
        .who { margin-top: 10px; font-size: 15px; color: #d5d6dc; }
        .who strong { color: #fff; }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 18px;
            margin-top: 14px;
        }

        .lede { color: var(--muted); font-size: 15px; margin: 0 0 4px; }

        .step-title { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--dim); margin-bottom: 10px; }

        /* ---------------------------------------------------- questions */
        .q { padding: 16px 0; border-bottom: 1px solid var(--line); }
        .q:first-of-type { padding-top: 4px; }
        .q:last-of-type { border-bottom: 0; padding-bottom: 4px; }
        .q-text { display: block; margin-bottom: 12px; }
        .q-text .n { color: var(--dim); margin-right: 6px; }

        .choice { display: flex; gap: 10px; }
        .choice label {
            flex: 1;
            display: block;
            text-align: center;
            padding: 13px 10px;
            border: 1.5px solid var(--line);
            border-radius: 11px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            user-select: none;
            background: #fff;
        }
        .choice input { position: absolute; opacity: 0; pointer-events: none; }
        .choice input:focus-visible + span { outline: 2px solid var(--accent); outline-offset: 3px; border-radius: 4px; }
        .choice label:has(input:checked) { border-color: var(--ink); background: var(--ink); color: #fff; }
        .choice label.yes:has(input:checked) { border-color: var(--accent); background: var(--accent); }

        .detail { margin-top: 12px; }
        .detail label { display: block; font-size: 14px; color: var(--muted); margin-bottom: 6px; }
        /* Hidden only when JS is driving; without it every detail box shows,
           which is correct but verbose rather than broken. */
        .js .detail[hidden] { display: none; }

        textarea, input[type="text"] {
            width: 100%;
            font: inherit;
            color: var(--ink);
            background: #fff;
            border: 1.5px solid var(--line);
            border-radius: 11px;
            padding: 12px 13px;
        }
        textarea { min-height: 78px; resize: vertical; }
        textarea:focus, input[type="text"]:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: transparent; }

        /* -------------------------------------------------------- terms */
        .terms {
            max-height: 240px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--line);
            border-radius: 11px;
            padding: 14px;
            background: #fcfcfd;
            font-size: 15px;
            color: #33353c;
        }
        .terms p { margin: 0 0 11px; }
        .terms p:last-child { margin-bottom: 0; }
        .terms-meta { font-size: 12px; color: var(--dim); margin-top: 8px; }

        .agree { display: flex; gap: 12px; align-items: flex-start; margin-top: 16px; cursor: pointer; }
        .agree input { width: 24px; height: 24px; margin: 1px 0 0; accent-color: var(--accent); flex: none; }

        /* ---------------------------------------------------- signature */
        .pad {
            position: relative;
            border: 1.5px dashed var(--line);
            border-radius: 11px;
            background: #fff;
            touch-action: none;
            overflow: hidden;
        }
        .pad canvas { display: block; width: 100%; height: 190px; }
        .pad .hint {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: var(--dim); font-size: 15px; pointer-events: none;
        }
        .pad.signed { border-style: solid; border-color: var(--ink); }
        .pad.signed .hint { display: none; }
        .pad-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 9px; }

        .link-button {
            background: none; border: 0; padding: 6px 0;
            color: var(--muted); font: inherit; text-decoration: underline; cursor: pointer;
        }

        /* ------------------------------------------------------ buttons */
        .btn {
            display: block; width: 100%;
            padding: 16px;
            font: inherit; font-weight: 700;
            border: 0; border-radius: 12px;
            background: var(--accent); color: #fff;
            cursor: pointer;
        }
        .btn:active { background: var(--accent-dark); }
        .btn[disabled] { background: #c9cbd2; cursor: not-allowed; }
        .btn.ghost { background: none; color: var(--muted); border: 1.5px solid var(--line); }

        .actions { margin-top: 18px; display: grid; gap: 10px; }

        /* ------------------------------------------------------- errors */
        .errors {
            border: 1.5px solid var(--accent);
            background: #fdf2f3;
            color: var(--accent-dark);
            border-radius: 11px;
            padding: 13px 15px;
            margin-top: 14px;
        }
        .errors ul { margin: 6px 0 0; padding-left: 18px; }

        .note { font-size: 13px; color: var(--dim); margin-top: 14px; }
        .noscript { border: 1.5px solid var(--accent); border-radius: 11px; padding: 13px; background: #fdf2f3; color: var(--accent-dark); }

        @media (prefers-reduced-motion: no-preference) {
            .card { transition: opacity .18s ease; }
        }
