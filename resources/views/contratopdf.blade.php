<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrato PDF</title>
    <style>
        @page {
            margin: 120px 10px 80px 10px;
        }
        body {
            font-family: Arial, sans-serif;
            margin: 10px;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .header {
            position: fixed;
            top: -100px;
            left: 0;
            right: 0;
            text-align: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .header img {
            max-width: 150px;
        }
        .content p {
            margin-bottom: 15px;
            text-align: justify;
            font-size: 11pt;
        }
        .content {
            margin-top: 20px;
        }
        #footer {
            position: fixed;
            bottom: -60px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }
        #footer .page:after {
            content: counter(page);
        }
        #footer .total:after {
            content: counter(pages);
        }
        .destaque {
            font-weight: bold;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ public_path('logo.png') }}" alt="Logo" class="logo">
    </div>
    
    <div class="container">
        <div class="content">
            @foreach($paragrafos as $paragrafo)
                <p>{{ $paragrafo }}</p>
            @endforeach
        </div>
    </div> 

    <div id="footer">
        <p>Data/Hora de aceitação: <span class="destaque">{{ $dataHora }}</span> - IP: <span class="destaque">{{ $ip }}</span></p>
        <p>Página <span class="page"></span></p>
    </div>
</body>
</html>