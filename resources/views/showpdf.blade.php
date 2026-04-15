<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visualizar PDF</title>
    <style>
        body, html {
            height: 100%;
            margin: 0;
            padding: 0;
            background-color: #f0f0f0;
        }
        iframe {
            width: 100%;
            height: 100vh; /* Altura completa da janela de visualização */
            border: none; /* Remove a borda do iframe */
        }
    </style>
</head>
<body>
    <iframe src="{{ $link }}" frameborder="0">
        Este navegador não suporta PDFs.
    </iframe>
</body>
</html>