<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cartão Amigo Saúde</title>
</head>
<body>

    <div id="header">
        <img src="https://storage.googleapis.com/flutterflow-io-6f20.appspot.com/projects/cartao-amigo-saude-otl9zt/assets/643jazuj5691/logo-amigo-saude.png" alt="Logo" class="logo">
        <p>Cartão Amigo Saúde</p>
    </div>

    <div id="footer">
        <p id="pagina"> Página <span class="page"></span> de {{ $data->paginas}}</p>       
    </div>

    <div class="main">
        <table class="beneficio-table" >
                <thead>
                    <tr>
                        <th style="width: 10%;">CPF</th>
                        <th style="width: 40%;">Beneficiário</th>
                        <th style="width: 10%;">Data Nascimento</th>
                        <th style="width: 10%;">Início</th>
                        <th style="width: 20%;">Plano</th>
                        <th style="width: 10%;">Valor</th>     
                    </tr>
                </thead>
                <tbody >
                    @foreach ($data->beneficios as $beneficio)
                    <tr>
                        <td>{{ $beneficio->cpf }}</td>
                        <td>{{ $beneficio->nome }}</td>
                        <td>{{ $beneficio->data_nascimento }}</td>
                        <td>{{ $beneficio->inicio }}</td>
                        <td>{{ $beneficio->valor_crt }}</td>
                        <td>{{ $beneficio->plano }}</td>
                        <td>{{ $beneficio->valor }}</td>
                    </tr>
                    @endforeach'

                    <tr class="resumo">
                        <td>qte: {{ $data->qtde }}</td>
                        <td class="vazio"></td> 
                        <td class="vazio"></td>
                        <td class="vazio"></td> 
                        <td class="vazio"></td> 
                        <td><strong>{{ $data->total }}</strong></td>
                    </tr>
                </tbody>
        </table>    		
    </div>

    <style>

        body {
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
            padding-bottom: 40px;
            font-weight: normal;
        }

        @page {
            margin: 80px 50px 0px 50px;
        }
        
        #header {
            position: fixed;
            top: -100px;
            left: 0;
            right: 0;
            height: 50px; 
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            line-height: 35px;
        }

        .beneficio-table .vazio {
            border: none; 
            padding: 0; 
        }

        #header .logo {
            height: 50px; 
            float: left; 
            margin-right: 0px;
            margin-top: 35px; 
        }

        #header p {
            line-height: 30px;
        }

        #footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 18px;
            font-size: 12px;
            color: #777;
            border-top: 1px solid #ddd;
            display: flex;
            justify-content: space-between; 
            align-items: center; 
            padding:  10px; 
            box-sizing: border-box;
            display: inline-block;
            width: 100%;
        }

        #footer p {
            margin: 0; 
            display: inline-block;
        }

        #footer #pagina {
            text-align: right;
        }

        #footer .page {
            font-weight: bold;
        }

        #footer .page:after {
            content: counter(page);
        }

        .page-break-after {
            page-break-after: always;
        }

        .beneficio-table {
            width: 100%;
            border-collapse: separate; 
            border-spacing: 0;
            font-size: 9px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            margin-top: 20px;
        }

        .beneficio-table th {
            background-color: #FF6A13;
            color: white;
            padding: 2px;
            text-align: left;
            border: 1px solid #ddd;
            font-weight: bold;
            margin: 0px;
        }

        .beneficio-table td {
            padding: 2px;
            border: 1px solid #ddd;
            text-align: left;
            margin: 0px;
        }

        .status-debito {
            color: #d14d00;
            font-weight: bold;
        }

        .beneficio-table td, .beneficio-table th {
            word-wrap: break-word;
            white-space: normal;
            max-width: 40px; 
            padding: 5px;
            padding-right: 1px;
            padding-left: 5px;
            margin: 0px;
        }

        .main{
            margin: 0px;
            padding: 0px;
        }

    </style> 
</body>
</html>