<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WebhookLog;
use stdClass;

class ConexaWebhookController extends Controller
{
    /**
     * Valida o token do webhook (se você forneceu um à Conexa)
     */
    private function validateToken(Request $request)
    {
        $expectedToken = config('conexa.webhook_token'); // Configure no .env
        $receivedToken = $request->header('token');
        
        if ($expectedToken && $receivedToken !== $expectedToken) {
            Log::warning('Webhook com token inválido', [
                'ip' => $request->ip(),
                'token' => $receivedToken
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Registra webhook recebido (importante para auditoria)
     */
    private function logWebhook($type, $data)
    {
        try {
            WebhookLog::create([
                'type' => $type,
                'payload' => json_encode($data),
                'received_at' => now()
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao registrar webhook', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 6.1 - WEBHOOK CHAMADA ACEITA
     * Disparado quando o médico chama o paciente
     */
    public function chamadaAceita(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: Chamada Aceita', $data);
        $this->logWebhook('chamada_aceita', $data);
        
        try {
            // Extrair dados
            $callId = $data['callId'] ?? null;
            $appointmentId = $data['appointmentId'] ?? null;
            $patientId = $data['patientId'] ?? null;
            $callUrl = $data['callUrl'] ?? null;
            
            // IMPLEMENTE SUA LÓGICA AQUI
            // Exemplos:
            // - Enviar SMS/email para o paciente com o link da chamada
            // - Atualizar status do atendimento no banco
            // - Notificar frontend via websocket
            // - Disparar notificação push
            
            // Exemplo: Atualizar banco
            \DB::table('atendimentos')
                ->where('appointment_id', $appointmentId)
                ->update([
                    'call_id' => $callId,
                    'call_url' => $callUrl,
                    'status' => 'em_chamada',
                    'chamada_iniciada_em' => now()
                ]);
            
            // Exemplo: Enviar notificação
            // $this->enviarNotificacaoVideo($patientId, $callUrl);
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook chamada aceita', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
        
        // IMPORTANTE: Sempre retornar 200 OK
        return response()->json(['status' => 'received'], 200);
    }

    /**
     * 6.2 - WEBHOOK ATENDIMENTO CONCLUÍDO
     * Disparado quando o médico finaliza o atendimento
     */
    public function atendimentoConcluido(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: Atendimento Concluído', $data);
        $this->logWebhook('atendimento_concluido', $data);
        
        try {
            $appointmentId = $data['appointmentId'] ?? null;
            $patientId = $data['patientId'] ?? null;
            $outcome = $data['outcome'] ?? null;
            $prescriptions = $data['prescriptions'] ?? [];
            $cid10 = $data['cid10'] ?? [];
            
            // IMPLEMENTE SUA LÓGICA AQUI
            // Exemplos:
            // - Atualizar status do atendimento
            // - Salvar prescrições
            // - Salvar CIDs
            // - Enviar email com resumo
            // - Gerar PDF do atendimento
            
            \DB::table('atendimentos')
                ->where('appointment_id', $appointmentId)
                ->update([
                    'status' => 'concluido',
                    'outcome' => $outcome,
                    'concluido_em' => now(),
                    'historico' => $data['historyPhysicalExamination'] ?? null,
                    'prescricao' => $data['prescription'] ?? null,
                    'diagnostico' => $data['clinicDiagnosis'] ?? null
                ]);
            
            // Salvar prescrições
            foreach ($prescriptions as $prescription) {
                \DB::table('prescricoes')->insert([
                    'appointment_id' => $appointmentId,
                    'prescription_id' => $prescription['id'] ?? null,
                    'url_download' => $prescription['urlDownload'] ?? null,
                    'data_prescricao' => $prescription['prescriptionDate'] ?? null,
                    'created_at' => now()
                ]);
            }
            
            // Salvar CIDs
            foreach ($cid10 as $cid) {
                \DB::table('atendimento_cids')->insert([
                    'appointment_id' => $appointmentId,
                    'cid' => $cid,
                    'created_at' => now()
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook atendimento concluído', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
        
        return response()->json(['status' => 'received'], 200);
    }

    /**
     * 6.3 - WEBHOOK PROXIMIDADE DE ATENDIMENTO
     * Disparado 5 minutos antes do atendimento agendado
     */
    public function proximidadeAtendimento(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: Proximidade de Atendimento', $data);
        $this->logWebhook('proximidade_atendimento', $data);
        
        try {
            $patientId = $data['patientId'] ?? null;
            
            // IMPLEMENTE SUA LÓGICA AQUI
            // - Enviar SMS/Email lembrando do atendimento
            // - Enviar notificação push
            // - Preparar sala de espera virtual
            
            // Exemplo: Enviar lembrete
            // $this->enviarLembreteAtendimento($patientId);
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook proximidade', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
        
        return response()->json(['status' => 'received'], 200);
    }

    /**
     * 6.4 - WEBHOOK ATENDIMENTO CANCELADO
     * Disparado quando o atendimento é cancelado
     */
    public function atendimentoCancelado(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: Atendimento Cancelado', $data);
        $this->logWebhook('atendimento_cancelado', $data);
        
        try {
            $appointmentId = $data['appointmentId'] ?? null;
            $patientId = $data['patientId'] ?? null;
            
            // IMPLEMENTE SUA LÓGICA AQUI
            \DB::table('atendimentos')
                ->where('appointment_id', $appointmentId)
                ->update([
                    'status' => 'cancelado',
                    'cancelado_em' => now()
                ]);
            
            // Notificar paciente
            // $this->notificarCancelamento($patientId);
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook cancelamento', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
        
        return response()->json(['status' => 'received'], 200);
    }

    /**
     * 6.5 - WEBHOOK CRIAÇÃO DE ATENDIMENTO
     * Disparado quando um atendimento é criado
     */
    public function criacaoAtendimento(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: Criação de Atendimento', $data);
        $this->logWebhook('criacao_atendimento', $data);
        
        try {
            $appointmentId = $data['appointmentId'] ?? null;
            $patientId = $data['patientId'] ?? null;
            $appointmentDate = $data['appointmentDate'] ?? null;
            
            // IMPLEMENTE SUA LÓGICA AQUI
            // - Registrar atendimento no banco
            // - Enviar confirmação ao paciente
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook criação', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
        
        return response()->json(['status' => 'received'], 200);
    }

    /**
     * 6.6 - WEBHOOK PDF DO ATENDIMENTO
     * Recebe o PDF do atendimento concluído
     */
    public function pdfAtendimento(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: PDF Atendimento', $data);
        $this->logWebhook('pdf_atendimento', $data);
        
        try {
            $cpf = $data['cpf_Paciente'] ?? null;
            $pdfBase64 = $data['anamnese_Paciente'] ?? null;
            
            if ($pdfBase64) {
                // Salvar PDF
                $filename = "atendimento_{$cpf}_" . time() . ".pdf";
                $path = storage_path("app/atendimentos/{$filename}");
                
                // Criar diretório se não existir
                if (!file_exists(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
                
                // Decodificar e salvar
                file_put_contents($path, base64_decode($pdfBase64));
                
                Log::info("PDF salvo: {$filename}");
                
                // Atualizar banco com caminho do PDF
                // \DB::table('atendimentos')->where('cpf', $cpf)->update(['pdf_path' => $filename]);
            }
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook PDF', [
                'error' => $e->getMessage(),
                'data' => array_merge($data, ['anamnese_Paciente' => '[base64 omitido]'])
            ]);
        }
        
        return response()->json(['status' => 'received'], 200);
    }

    /**
     * 6.7 - WEBHOOK NPS DO ATENDIMENTO
     * Recebe avaliações do atendimento
     */
    public function npsAtendimento(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: NPS Atendimento', $data);
        $this->logWebhook('nps_atendimento', $data);
        
        try {
            $appointmentNPS = $data['appointmentNPS'] ?? [];
            
            foreach ($appointmentNPS as $npsData) {
                $nps = $npsData['nps'] ?? [];
                
                \DB::table('avaliacoes')->insert([
                    'appointment_id' => $nps['appoinmentId'] ?? null,
                    'score' => $nps['score'] ?? null,
                    'nps_scale' => $nps['npsScale'] ?? null,
                    'comment' => $nps['comment'] ?? null,
                    'evaluation_type' => $nps['evaluationType'] ?? null,
                    'appointment_shape' => $nps['appointmentShape'] ?? null,
                    'created_at' => now()
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook NPS', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
        
        return response()->json(['status' => 'received'], 200);
    }

    /**
     * 6.8 - WEBHOOK NPS NÃO RESPONDIDO
     */
    public function npsNaoRespondido(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: NPS Não Respondido', $data);
        $this->logWebhook('nps_nao_respondido', $data);
        
        try {
            $patientCpf = $data['patientCpf'] ?? null;
            
            // Enviar lembrete para avaliar
            // $this->enviarLembreteAvaliacao($patientCpf);
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook NPS não respondido', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
        
        return response()->json(['status' => 'received'], 200);
    }

    /**
     * 6.9 - WEBHOOK PACIENTE REMOVIDO DA FILA
     */
    public function pacienteRemovidoFila(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: Paciente Removido da Fila', $data);
        $this->logWebhook('paciente_removido_fila', $data);
        
        try {
            $patientId = $data['patientId'] ?? null;
            
            // Atualizar status
            \DB::table('atendimentos_imediatos')
                ->where('patient_id', $patientId)
                ->update([
                    'status' => 'removido_fila',
                    'removido_em' => now()
                ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook removido fila', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
        
        return response()->json(['status' => 'received'], 200);
    }

    /**
     * 6.10 - WEBHOOK STATUS DA VIDEOCHAMADA
     */
    public function statusVideochamada(Request $request)
    {
        if (!$this->validateToken($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $data = $request->all();
        
        Log::info('Webhook: Status Videochamada', $data);
        $this->logWebhook('status_videochamada', $data);
        
        try {
            $event = $data['event'] ?? null; // ready, ongoing, done, error
            $appointmentId = $data['data']['appointmentId'] ?? null;
            $callId = $data['data']['callId'] ?? null;
            
            $statusMap = [
                'ready' => 'pronto',
                'ongoing' => 'em_andamento',
                'done' => 'finalizado',
                'error' => 'erro'
            ];
            
            \DB::table('chamadas_video')->updateOrInsert(
                ['call_id' => $callId],
                [
                    'appointment_id' => $appointmentId,
                    'status' => $statusMap[$event] ?? 'desconhecido',
                    'event' => $event,
                    'error_detail' => $data['error']['detail'] ?? null,
                    'updated_at' => now()
                ]
            );
            
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook status videochamada', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
        
        return response()->json(['status' => 'received'], 200);
    }
}