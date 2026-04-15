<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher PDF</title>
<style>
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
    }
    .container {
        border: 1px solid #f0f0f0;
        padding: 8px;
        box-sizing: border-box;
        position: relative;
    }
    .content {
        margin: 0;
    }
    .header {
        text-align: center;
        margin-bottom: 8px;
    }
    .header img {
        max-width: 300px;
    }
    .form-field {
        border: none;
        border-bottom: 2px solid #e0e0e0;
        padding: 8px 12px;
        margin-bottom: 8px;
        width: calc(100% - 24px);
        box-sizing: border-box;
        background-color: #fafafa;
        border-radius: 4px 4px 0 0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
    }
    .form-field:focus {
        outline: none;
        border-bottom: 2px solid #2196f3;
        background-color: #ffffff;
        box-shadow: 0 2px 6px rgba(33,150,243,0.2);
    }
    .form-label {
        font-weight: bold;
        color: #1976d2;
        font-size: 14px;
        margin-bottom: 2px;
    }
    .footer {
        font-size: 12px;
        text-align: center;
        margin-top: 30px;
    }
</style>
</head>
<body>
    <div class="container">

        <div class="content">
			<div class="header">
				<img src="{{ public_path('logo.png') }}" alt="Logo" class="logo">
            </div>
            <div>
				
            <div style="margin-bottom: 20px; padding: 15px; background-color: #e3f2fd; border: 2px solid #2196f3; border-radius: 8px; text-align: center;">
                <div class="form-label" style="color: #1976d2; font-weight: bold; font-size: 14px; margin-bottom: 5px;">NÚMERO DO VOUCHER</div>
                <div style="font-size: 24px; font-weight: bold; color: #333;">{{ $voucher->numero_voucher }}</div>
            </div>

            <div style="padding: 15px; background-color: #e3f2fd; border: 2px solid #2196f3; border-radius: 8px;">
                <div class="form-label">Nome do paciente</div>
                <div class="form-field">{{ $voucher->paciente }}</div>

                <div class="form-label">Data de nascimento</div>
                <div class="form-field">{{ $voucher->data_nascimento }}</div>

                <div class="form-label">Consulta/Exame</div>
                <div class="form-field">{{ $voucher->especialidade }}</div>

                <div class="form-label">Clínica</div>
                <div class="form-field">{{ $voucher->clinica }}</div>

				<div class="form-label">Endereço</div>
                <div class="form-field">{{ $voucher->endereco }}</div>
				
				<div class="form-label">Telefone</div>
                <div class="form-field">{{ $voucher->telefone }}</div>
				
                <div class="form-label">Data/Hora</div>
                <div class="form-field">{{ $voucher->data_hora }}</div>
				
				@if ($voucher->mostrar_valor == 1)
				<div class="form-label">Valor</div>
                <div class="form-field">{{ $voucher->valor }}</div>
				@endif
				
                <div class="form-label">Assinatura</div>
                <div class="form-field">{{ "" }}</div>
            </div>
            </div>
            <div class="footer">
                Rua Valentim Amaral, 236, Santa Cruz, São Pedro/SP - SAC (19) 98951-2404 / Agendamentos (19) 99855-7120<br>
                cartaoamigosaude.com.br - sac@cartaoamigosaude.com.br - agendamentoamigosaude@gmail.com
            </div>
        </div>
    </div>
</body>
</html>
