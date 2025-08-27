<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Cipher</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg,#0f172a,#1e293b);
            color: #e2e8f0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            background: rgba(30,41,59,0.95);
            padding: 24px;
            border-radius: 16px;
            width: 90%;
            max-width: 900px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .header h1 {
            margin: 0;
            font-size: 1.5rem;
        }
        .panels {
            display: flex;
            gap: 20px;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        .panel {
            flex: 1 1 350px;
        }
        .panel label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
        }
        textarea {
            width: 100%;
            height: 200px;
            resize: none;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #334155;
            background: #0f172a;
            color: #e2e8f0;
        }
        textarea:focus {
            outline: 2px solid #3b82f6;
        }
        .buttons {
            display: flex;
            gap: 10px;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        button {
            flex: 1 1 120px;
            padding: 10px;
            border: none;
            border-radius: 8px;
            background: #3b82f6;
            color: #fff;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover {
            background: #2563eb;
        }
        .tip {
            margin-top: 0.5rem;
            text-align: center;
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .toggle {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Shift Cipher</h1>
            <label class="toggle"><input type="checkbox" id="live"> Live transform</label>
        </div>
        <div class="panels">
            <div class="panel">
                <label>Input <span id="inputCount">0</span> chars</label>
                <textarea id="input" placeholder="Enter text here..."></textarea>
            </div>
            <div class="panel">
                <label>Output <span id="outputCount">0</span> chars</label>
                <textarea id="output" readonly></textarea>
            </div>
        </div>
        <div class="buttons">
            <button id="encryptBtn">Encrypt</button>
            <button id="decryptBtn">Decrypt</button>
            <button id="swapBtn">Swap</button>
            <button id="copyBtn">Copy</button>
            <button id="clearBtn">Clear</button>
        </div>
        <p class="tip">Tip: (Ctrl/⌘ + E encrypts, Ctrl/⌘ + D decrypts)</p>
    </div>
    <script>
        const vowelsLower = ['a','e','i','o','u'];
        const consonantsLower = ['b','c','d','f','g','h','j','k','l','m','n','p','q','r','s','t','v','w','x','y','z'];
        const vowelsUpper = vowelsLower.map(v => v.toUpperCase());
        const consonantsUpper = consonantsLower.map(c => c.toUpperCase());

        function buildMap(mode) {
            const map = {};
            if (mode === 'encode') {
                for (let i = 0; i < vowelsLower.length; i++) {
                    map[vowelsLower[i]] = vowelsLower[(i + 1) % vowelsLower.length];
                    map[vowelsUpper[i]] = vowelsUpper[(i + 1) % vowelsUpper.length];
                }
                for (let i = 0; i < consonantsLower.length; i++) {
                    map[consonantsLower[i]] = consonantsLower[(i + 1) % consonantsLower.length];
                    map[consonantsUpper[i]] = consonantsUpper[(i + 1) % consonantsUpper.length];
                }
            } else {
                for (let i = 0; i < vowelsLower.length; i++) {
                    map[vowelsLower[i]] = vowelsLower[(i - 1 + vowelsLower.length) % vowelsLower.length];
                    map[vowelsUpper[i]] = vowelsUpper[(i - 1 + vowelsUpper.length) % vowelsUpper.length];
                }
                for (let i = 0; i < consonantsLower.length; i++) {
                    map[consonantsLower[i]] = consonantsLower[(i - 1 + consonantsLower.length) % consonantsLower.length];
                    map[consonantsUpper[i]] = consonantsUpper[(i - 1 + consonantsUpper.length) % consonantsUpper.length];
                }
            }
            return map;
        }

        function shiftCipher(text, mode) {
            const map = buildMap(mode);
            return text.split('').map(ch => map[ch] || ch).join('');
        }

        const input = document.getElementById('input');
        const output = document.getElementById('output');
        const live = document.getElementById('live');
        const inputCount = document.getElementById('inputCount');
        const outputCount = document.getElementById('outputCount');
        let currentMode = 'encode';

        function updateCounts() {
            inputCount.textContent = input.value.length;
            outputCount.textContent = output.value.length;
        }

        function transform(mode) {
            currentMode = mode;
            output.value = shiftCipher(input.value, mode);
            updateCounts();
        }

        input.addEventListener('input', () => {
            updateCounts();
            if (live.checked) transform(currentMode);
        });

        document.getElementById('encryptBtn').addEventListener('click', () => transform('encode'));
        document.getElementById('decryptBtn').addEventListener('click', () => transform('decode'));
        document.getElementById('swapBtn').addEventListener('click', () => {
            [input.value, output.value] = [output.value, input.value];
            updateCounts();
            if (live.checked) transform(currentMode);
        });
        document.getElementById('copyBtn').addEventListener('click', () => {
            navigator.clipboard.writeText(output.value);
        });
        document.getElementById('clearBtn').addEventListener('click', () => {
            input.value = '';
            output.value = '';
            updateCounts();
        });

        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'e') {
                e.preventDefault();
                transform('encode');
            }
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') {
                e.preventDefault();
                transform('decode');
            }
        });

        updateCounts();
    </script>
</body>
</html>
