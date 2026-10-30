<!doctype html>
<html lang="$ContentLocale">
<head>
    <% base_tag %>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>$Title.XML</title>
    <style>
        html { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f4f6f8; color: #233044; }
        main { box-sizing: border-box; width: min(44rem, calc(100% - 2rem)); margin: 2rem 0; padding: clamp(1.5rem, 5vw, 3rem); background: white; border-radius: 1rem; box-shadow: 0 1rem 3rem #23304415; }
        h1 { margin-top: 0; line-height: 1.2; overflow-wrap: anywhere; }
        .content { line-height: 1.65; overflow-wrap: anywhere; }
        img, video { max-width: 100%; height: auto; }
        a { color: #1762a9; }
        @media (prefers-color-scheme: dark) {
            body { background: #151c26; color: #e8edf4; }
            main { background: #233044; }
            a { color: #92c8ff; }
        }
    </style>
</head>
<body>
    <main>
        <h1>$Title.XML</h1>
        <div class="content">$Content</div>
    </main>
</body>
</html>
