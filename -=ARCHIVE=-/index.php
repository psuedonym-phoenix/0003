<?php
function shiftCipher($text, $mode='encode') {
    $vowelsLower = ['a','e','i','o','u'];
    $consonantsLower = ['b','c','d','f','g','h','j','k','l','m','n','p','q','r','s','t','v','w','x','y','z'];
    $vowelsUpper = array_map('strtoupper', $vowelsLower);
    $consonantsUpper = array_map('strtoupper', $consonantsLower);

    $map = [];
    if ($mode === 'encode') {
        for ($i=0; $i<count($vowelsLower); $i++) {
            $map[$vowelsLower[$i]] = $vowelsLower[($i+1)%count($vowelsLower)];
            $map[$vowelsUpper[$i]] = $vowelsUpper[($i+1)%count($vowelsUpper)];
        }
        for ($i=0; $i<count($consonantsLower); $i++) {
            $map[$consonantsLower[$i]] = $consonantsLower[($i+1)%count($consonantsLower)];
            $map[$consonantsUpper[$i]] = $consonantsUpper[($i+1)%count($consonantsUpper)];
        }
    } else {
        for ($i=0; $i<count($vowelsLower); $i++) {
            $map[$vowelsLower[$i]] = $vowelsLower[($i-1+count($vowelsLower))%count($vowelsLower)];
            $map[$vowelsUpper[$i]] = $vowelsUpper[($i-1+count($vowelsUpper))%count($vowelsUpper)];
        }
        for ($i=0; $i<count($consonantsLower); $i++) {
            $map[$consonantsLower[$i]] = $consonantsLower[($i-1+count($consonantsLower))%count($consonantsLower)];
            $map[$consonantsUpper[$i]] = $consonantsUpper[($i-1+count($consonantsUpper))%count($consonantsUpper)];
        }
    }
    return strtr($text, $map);
}

$input = '';
$output = '';
$mode = 'encode';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST['text'] ?? '';
    $mode = $_POST['mode'] ?? 'encode';
    $output = shiftCipher($input, $mode);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Cipher</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); width: 90%; max-width: 400px; }
        textarea { width: 100%; min-height: 100px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .result { margin-top: 10px; padding: 10px; background: #e9ecef; border-radius: 4px; min-height: 50px; }
        button { padding: 10px 20px; margin-top: 10px; border: none; border-radius: 4px; background: #007bff; color: white; cursor: pointer; width: 100%; }
        button:hover { background: #0056b3; }
        .mode { display: flex; gap: 10px; margin-top: 10px; }
        .mode label { flex: 1; }
        h1 { text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Shift Cipher</h1>
        <form method="post">
            <textarea name="text" placeholder="Enter text here..."><?php echo htmlspecialchars($input); ?></textarea>
            <div class="mode">
                <label><input type="radio" name="mode" value="encode" <?php if($mode==='encode') echo 'checked'; ?>> Encode</label>
                <label><input type="radio" name="mode" value="decode" <?php if($mode==='decode') echo 'checked'; ?>> Decode</label>
            </div>
            <button type="submit">Run</button>
        </form>
        <?php if($output !== ''): ?>
        <div class="result"><?php echo htmlspecialchars($output); ?></div>
        <?php endif; ?>
    </div>
</body>
</html>
