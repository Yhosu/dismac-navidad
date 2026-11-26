<?php

/**
 * Promoción Dismac Navidad
 *
 * PHP version 8.2
 *
 * @abstract  Controlador Principal de API REST PARA PARTICIPANTES - Promoción Dismac Navidad
 *
 * @category  Production
 *
 * @copyright 2026
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InformalMarketFormRequest;
use App\Http\Requests\MarkAsUsedRequest;
use App\Http\Requests\RegisterParticipantRequest;
use App\Interfaces\ParticipantServiceInteface;
use App\Models\Form;
use App\Models\Participant;
use App\Services\ApiResponseService;
use App\Services\FileUploadService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppController extends Controller
{
    public function __construct(
        private readonly ParticipantServiceInteface $participantService
    ) {}

    /**
     * Registrar Participante
     *
     * Registra un nuevo participante y su compra para la promoción **DISMAC NAVIDAD**, determinando la esfera correspondiente según el monto total de la compra, el límite diario por cédula de identidad y el canal (`ECO` vs físico/POS).
     *
     * ### Reglas de Participación y Esferas:
     * - **Límite diario**: Máximo **1 participación válida por día** por cédula de identidad (`ci_number`).
     * - **Monto mínimo**: Compras menores a `Bs. 1.500` no generan participación (`total_attempts = 0`).
     * - **Esfera Roja (`T1`)**: Compras de `Bs. 1.500` a `2.999` (vales posibles de `Bs. 50` y `100`).
     * - **Esfera Plateada (`T2`)**: Compras de `Bs. 3.000` a `5.499` (vales posibles de `Bs. 100`, `200` y `500`).
     * - **Esfera Dorada (`T3`)**: Compras desde `Bs. 5.500` (vales posibles de `Bs. 200` a `10.000`).
     *
     * ### Comportamiento por Canal (`business_unit`):
     * - **Ecommerce (`ECO`)**: El sistema asigna automáticamente un premio con stock disponible en la sucursal Ecommerce dentro del rango de la esfera, descuenta el inventario y `message_promotion` indica el color de esfera ganado y que el cliente debe **reventar su globo virtual** en `participant_link`.
     * - **Físico / POS (`RET`, `ACE`, etc.)**: No se asigna premio automáticamente en el registro; `message_promotion` indica el color de esfera ganado y que el cliente debe acercarse al **punto de canje físico (POS)** en sucursal donde el operador asignará y entregará el premio desde el sistema.
     *
     * ### Explicación de los campos devueltos en `data`:
     * - `total_attempts` *(int)*: **Cantidad de participaciones generadas** en el registro (`1` si califica y no participó hoy, `0` si no alcanza el monto mínimo o ya participó hoy).
     * - `sphere_color` *(string|null)*: Color de la esfera ganada (`"Roja"`, `"Plateada"`, `"Dorada"` o `null` si no generó intento).
     * - `sphere_tier` *(string|null)*: Nivel de la esfera (`"T1"`, `"T2"`, `"T3"` o `null`).
     * - `is_ecommerce` *(bool)*: Indica si la compra pertenece al canal Ecommerce (`business_unit === 'ECO'`).
     * - `message_promotion` *(string)*: **Mensaje dinámico informativo** con el color de esfera ganado y la instrucción correspondiente (reventar globo virtual en Ecommerce o acercarse al POS físico).
     * - `generate_participant_code` *(string)*: Identificador único (UUID) del participante registrado.
     * - `participant_link` *(string)*: URL pública directa (`/participate/{ci}`) para que el usuario Ecommerce reviente su globo virtual.
     * - `participant_name` *(string)*: Nombre completo del cliente registrado.
     * - `participant_email` *(string|null)*: Correo electrónico registrado.
     * - `participant_ci` *(string)*: Carnet de identidad o documento de identidad del participante.
     *
     * @return array{process: bool, message: string, data: array{generate_participant_code: string, participant_link: string, participant_name: string, participant_email: string|null, participant_ci: string, total_attempts: int, sphere_color: string|null, sphere_tier: string|null, is_ecommerce: bool, message_promotion: string}, errors: array<int, string>}
     */
    #[Response(
        status: 200,
        description: 'Participante registrado con éxito',
        examples: [
            'Registro Ecommerce (Globo Virtual)' => [
                'process' => true,
                'message' => 'Participante registrado con éxito.',
                'data' => [
                    'generate_participant_code' => 'b7e28938-1a52-4f1e-9276-32454a867b32',
                    'participant_link' => 'https://promociones.dismac.com.bo/participate/12345678',
                    'participant_name' => 'Juan Pérez',
                    'participant_email' => 'juan.perez@example.com',
                    'participant_ci' => '12345678',
                    'total_attempts' => 1,
                    'sphere_color' => 'Plateada',
                    'sphere_tier' => 'T2',
                    'is_ecommerce' => true,
                    'message_promotion' => '¡Felicidades! Ganaste una Esfera Plateada (T2) en la promoción Dismac Navidad. Debes reventar tu globo virtual en el siguiente enlace para descubrir tu premio.',
                ],
                'errors' => [],
            ],
            'Registro Físico / POS' => [
                'process' => true,
                'message' => 'Participante registrado con éxito.',
                'data' => [
                    'generate_participant_code' => 'b7e28938-1a52-4f1e-9276-32454a867b32',
                    'participant_link' => 'https://promociones.dismac.com.bo/participate/12345678',
                    'participant_name' => 'Juan Pérez',
                    'participant_email' => 'juan.perez@example.com',
                    'participant_ci' => '12345678',
                    'total_attempts' => 1,
                    'sphere_color' => 'Roja',
                    'sphere_tier' => 'T1',
                    'is_ecommerce' => false,
                    'message_promotion' => '¡Felicidades! Ganaste una Esfera Roja (T1) en la promoción Dismac Navidad. Acércate al punto de canje físico (POS) en sucursal para reclamar tu premio.',
                ],
                'errors' => [],
            ],
        ]
    )]
    #[Response(
        status: 400,
        description: 'Error en los parámetros o participante no permitido',
        examples: [
            'Error de Participación' => [
                'process' => false,
                'message' => 'Error al crear el participante',
                'errors' => ['Este participante no puede participar de esta promoción.'],
                'data' => [],
            ],
        ]
    )]
    #[Response(
        status: 401,
        description: 'API Key inválida',
        examples: [
            'No Autorizado' => [
                'message' => 'Unauthorized: Invalid or missing API Key',
            ],
        ]
    )]
    #[Response(
        status: 422,
        description: 'Error de validación de campos',
        examples: [
            'Campos Requeridos' => [
                'process' => false,
                'message' => 'Error en el envío de parámetros',
                'errors' => [
                    'El carnet de identidad es obligatorio.',
                    'La ciudad es obligatoria',
                ],
                'data' => [],
            ],
        ]
    )]
    public function registerParticipant(RegisterParticipantRequest $request)
    {
        try {
            $participant = $this->participantService->registerParticipant($request->all());

            return ApiResponseService::success(
                message: 'Participante registrado con éxito.',
                data: $participant
            );
        } catch (\Exception $e) {
            Log::error('Error register participant', [
                'error' => $e->getMessage(),
            ]);
            $method = method_exists($e, 'getStatusCode') ? 'getStatusCode' : 'getCode';
            $responseMethod = $e->$method() == 0 ? 500 : $e->$method();

            return ApiResponseService::error('Error al crear el participante', [$e->getMessage()], (object) [], (object) [], 400);
        }
    }

    /**
     * Obtener Datos del Participante
     *
     * Consulta el estado actual de un participante mediante su CI/documento y devuelve sus datos junto con las participaciones disponibles en la promoción **DISMAC NAVIDAD**.
     *
     * ### Explicación de los campos devueltos en `data`:
     * - `total_attempts` *(int)*: **Cantidad de participaciones disponibles y vigentes** en estado `registered`.
     * - `sphere_color` *(string|null)*: Color de la esfera del último formulario registrado (`"Roja"`, `"Plateada"`, `"Dorada"` o `null`).
     * - `sphere_tier` *(string|null)*: Nivel de la esfera (`"T1"`, `"T2"`, `"T3"` o `null`).
     * - `is_ecommerce` *(bool)*: Indica si la participación vigente corresponde al canal Ecommerce (`ECO`).
     * - `message_promotion` *(string)*: **Mensaje de estado para el cliente**:
     *   - Si tiene participación vigente Ecommerce: indica el color de esfera ganado y que debe reventar su globo virtual.
     *   - Si tiene participación vigente en tienda física: indica el color de esfera ganado y que debe acercarse al punto de canje físico (POS).
     *   - Sin intentos disponibles (`0`): *"No cuenta con intentos disponibles en este momento."*
     * - `generate_participant_code` *(string)*: Identificador único (UUID) del participante.
     * - `participant_link` *(string)*: URL directa (`/participate/{ci}`) para reventar el globo virtual en caso de Ecommerce.
     * - `participant_name` *(string)*: Nombre del cliente.
     * - `participant_email` *(string|null)*: Correo electrónico del cliente.
     * - `participant_ci` *(string)*: Número de carnet de identidad consultado.
     *
     * @return array{process: bool, message: string, data: array{generate_participant_code: string, participant_link: string, participant_name: string, participant_email: string|null, participant_ci: string, total_attempts: int, sphere_color: string|null, sphere_tier: string|null, is_ecommerce: bool, message_promotion: string}, errors: array<int, string>}
     */
    #[Response(
        status: 200,
        description: 'Participante obtenido con éxito',
        examples: [
            'Consulta Exitosa (Ecommerce)' => [
                'process' => true,
                'message' => 'Participante obtenido con éxito.',
                'data' => [
                    'generate_participant_code' => 'b7e28938-1a52-4f1e-9276-32454a867b32',
                    'participant_link' => 'https://promociones.dismac.com.bo/participate/12345678',
                    'participant_name' => 'Juan Pérez',
                    'participant_email' => 'juan.perez@example.com',
                    'participant_ci' => '12345678',
                    'total_attempts' => 1,
                    'sphere_color' => 'Dorada',
                    'sphere_tier' => 'T3',
                    'is_ecommerce' => true,
                    'message_promotion' => '¡Felicidades! Ganaste una Esfera Dorada (T3) en la promoción Dismac Navidad. Debes reventar tu globo virtual para descubrir tu premio.',
                ],
                'errors' => [],
            ],
        ]
    )]
    #[Response(
        status: 400,
        description: 'Participante no encontrado o error en la consulta',
        examples: [
            'No Encontrado' => [
                'process' => false,
                'message' => 'Error al obtener el participante',
                'errors' => ['El participante no se encuentra registrado.'],
                'data' => [],
            ],
        ]
    )]
    #[Response(
        status: 401,
        description: 'API Key inválida',
        examples: [
            'No Autorizado' => [
                'message' => 'Unauthorized: Invalid or missing API Key',
            ],
        ]
    )]
    public function getDataParticipant($ciNumber)
    {
        try {
            $participant = $this->participantService->findByCiNumber($ciNumber);

            return ApiResponseService::success(
                message: 'Participante obtenido con éxito.',
                data: $participant
            );
        } catch (\Exception $e) {
            Log::error('Error register participant', ['error' => $e->getMessage()]);
            $method = method_exists($e, 'getStatusCode') ? 'getStatusCode' : 'getCode';
            $responseMethod = $e->$method() == 0 ? 500 : $e->$method();

            return ApiResponseService::error('Error al obtener el participante', [$e->getMessage()], (object) [], (object) [], $responseMethod);
        }
    }

    public function getPrices()
    {
        try {
            $prices = $this->participantService->getPrices();

            return ApiResponseService::success(
                message: 'Premios obtenidos exitósamente.',
                data: $prices
            );
        } catch (\Exception $e) {
            Log::error('Error register participant', ['error' => $e->getMessage()]);
            $method = method_exists($e, 'getStatusCode') ? 'getStatusCode' : 'getCode';
            $responseMethod = $e->$method() == 0 ? 500 : $e->$method();

            return ApiResponseService::error('Error al obtener el participante', [$e->getMessage()], [], [], $responseMethod);
        }
    }

    public function getJsonParticipants()
    {
        try {
            $result = $this->participantService->getJsonParticipants();

            return ApiResponseService::success(
                message: 'Premios obtenidos exitósamente.',
                data: $result
            );
        } catch (\Exception $e) {
            Log::error('Error register participant', ['error' => $e->getMessage()]);
            $method = method_exists($e, 'getStatusCode') ? 'getStatusCode' : 'getCode';
            $responseMethod = $e->$method() == 0 ? 500 : $e->$method();

            return ApiResponseService::error('Error al obtener el participante', [$e->getMessage()], [], [], $responseMethod);
        }
    }

    public function postUpdateWinner(Request $request)
    {
        try {
            $result = $this->participantService->updateWinner($request->all());

            return ApiResponseService::success(
                message: 'Cupón ganador actualizado correctamente.',
                data: []
            );
        } catch (\Exception $e) {
            Log::error('Error register participant', ['error' => $e->getMessage()]);
            $method = method_exists($e, 'getStatusCode') ? 'getStatusCode' : 'getCode';
            $responseMethod = $e->$method() == 0 ? 500 : $e->$method();

            return ApiResponseService::error('Error al obtener el participante', [$e->getMessage()], [], [], $responseMethod);
        }
    }

    public function getWinnersByPrice($priceId)
    {
        try {
            $result = $this->participantService->getWinnersByPrice($priceId);

            return ApiResponseService::success(
                message: 'Premios obtenidos exitósamente.',
                data: $result
            );
        } catch (\Exception $e) {
            Log::error('Error register participant', ['error' => $e->getMessage()]);
            $method = method_exists($e, 'getStatusCode') ? 'getStatusCode' : 'getCode';
            $responseMethod = $e->$method() == 0 ? 500 : $e->$method();

            return ApiResponseService::error('Error al obtener el participante', [$e->getMessage()], [], [], $responseMethod);
        }
    }

    /**
     * @exclude
     */
    public function checkCode(string $code)
    {
        try {
            $result = $this->participantService->checkCodeAttempts($code);

            if (! ($result['success'] ?? false)) {
                return ApiResponseService::error(
                    message: $result['message'],
                    errors: $result['errors'],
                    data: $result['data'],
                    others: [],
                    code: $result['status_code']
                );
            }

            return ApiResponseService::success(
                message: $result['message'],
                data: $result['data']
            );
        } catch (\Exception $e) {
            Log::error('Error in checkCode', [
                'code' => $code,
                'error' => $e->getMessage(),
            ]);
            $method = method_exists($e, 'getStatusCode') ? 'getStatusCode' : 'getCode';
            $responseMethod = $e->$method() == 0 ? 500 : $e->$method();

            return ApiResponseService::error(
                message: 'Error al validar el código.',
                errors: [$e->getMessage()],
                data: (object) [],
                others: [],
                code: $responseMethod
            );
        }
    }

    /**
     * @exclude
     */
    public function markAsUsed(MarkAsUsedRequest $request)
    {
        try {
            $id = $request->input('id');
            $controlCode = $request->input('control_code') ?: $request->input('codigo_control');

            $result = $this->participantService->markFormAsUsed($id, $controlCode);

            if (! ($result['success'] ?? false)) {
                return ApiResponseService::error(
                    message: $result['message'],
                    errors: $result['errors'],
                    data: $result['data'],
                    others: [],
                    code: $result['status_code']
                );
            }

            return ApiResponseService::success(
                message: $result['message'],
                data: $result['data']
            );
        } catch (\Exception $e) {
            Log::error('Error in markAsUsed', [
                'id' => $request->input('id'),
                'error' => $e->getMessage(),
            ]);
            $method = method_exists($e, 'getStatusCode') ? 'getStatusCode' : 'getCode';
            $responseMethod = $e->$method() == 0 ? 500 : $e->$method();

            return ApiResponseService::error(
                message: 'Error al marcar el formulario como utilizado.',
                errors: [$e->getMessage()],
                data: (object) [],
                others: [],
                code: $responseMethod
            );
        }
    }

    /**
     * @exclude
     */
    public function informalMarketForm(InformalMarketFormRequest $request)
    {
        try {
            $form = $this->participantService->registerInformalMarketForm($request->all());

            return ApiResponseService::create(
                message: 'Su formulario se ha llenado con éxito.',
                data: [
                    'id' => $form->id,
                    'code' => $form->code,
                    'name' => $form->name,
                    'ci_number' => $form->ci_number,
                    'cellphone' => $form->cellphone,
                    'city' => $form->city,
                    'status' => $form->status,
                    'type' => $form->type,
                    'participant_id' => $form->participant_id,
                    'invoice_url' => $form->invoice_url,
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponseService::error(
                message: 'Error en el archivo enviado.',
                errors: [$e->getMessage()],
                data: (object) [],
                others: [],
                code: 422
            );
        } catch (\Exception $e) {
            Log::error('Error in informalMarketForm', [
                'error' => $e->getMessage(),
            ]);
            $method = method_exists($e, 'getStatusCode') ? 'getStatusCode' : 'getCode';
            $responseMethod = $e->$method() == 0 ? 500 : $e->$method();

            return ApiResponseService::error(
                message: 'Error al procesar el formulario.',
                errors: [$e->getMessage()],
                data: (object) [],
                others: [],
                code: $responseMethod
            );
        }
    }
}

