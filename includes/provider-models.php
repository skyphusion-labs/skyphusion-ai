<?php
/**
 * WordPress AI Client provider for Workers AI, optionally through AI Gateway.
 *
 * Loaded only after the AI Client interfaces exist. The API token is the
 * Connectors key (CLOUDFLARE_API_KEY). This file never writes that token.
 */

/*
 * NOT COVERED BY tests/endpoint-test.php, AND THAT IS A DECISION.
 *
 * Measured with pcov: this file is never loaded by the harness, so it has no
 * coverage denominator at all rather than zero coverage. 19,553 bytes.
 *
 * Covering it would mean stubbing AbstractApiBasedModel and ModelMetadata,
 * which belong to the WordPress AI Client and not to this repo. A stub of
 * another package's class hierarchy encodes an assumption about that package,
 * and when the package changes shape the test keeps passing against a shape
 * the SDK no longer has. That is a fixture that lies later, quietly, in the
 * reassuring direction, so it was declined rather than deferred.
 *
 * What IS covered is the path that protects a site without the SDK:
 * tests/provider-guard-test.php asserts cloudflare_ai_provider_boot() never
 * requires this file while the AI Client is absent. The risk this file
 * carries is being loaded without its types, and that is the assertion.
 *
 * See skyphusion-ai issue 3. Do not read the gap as an oversight.
 */

if (!defined('ABSPATH')) {
    exit;
}

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

class Cloudflare_AI_Provider extends AbstractApiProvider
{
    protected static function baseUrl(): string
    {
        return 'https://api.cloudflare.com';
    }

    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'cloudflare',
            'Cloudflare',
            ProviderTypeEnum::cloud(),
            'https://dash.cloudflare.com/?to=/:account/ai/workers-ai', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- dashboard link, not a remote asset.
            RequestAuthenticationMethod::apiKey(),
            'Workers AI text, images, and speech. AI Gateway when a gateway id is set. AI Search stays on the public endpoint.',
            null
        );
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new Cloudflare_AI_Provider_Availability();
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new Cloudflare_AI_Model_Directory();
    }

    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): \WordPress\AiClient\Providers\Models\Contracts\ModelInterface
    {
        $entry = cloudflare_ai_provider_catalog_entry($modelMetadata->getId());
        if ($entry === null) {
            throw new InvalidArgumentException(esc_html__('That Cloudflare model is not in the catalog.', 'skyphusion-ai'));
        }
        if ($entry['kind'] === 'image') {
            return new Cloudflare_AI_Image_Model($modelMetadata, $providerMetadata);
        }
        if ($entry['kind'] === 'speech') {
            if (!class_exists('Cloudflare_AI_Speech_Model', false)) {
                throw new InvalidArgumentException(esc_html__('Speech generation is not available in this WordPress version.', 'skyphusion-ai'));
            }
            return new Cloudflare_AI_Speech_Model($modelMetadata, $providerMetadata);
        }
        return new Cloudflare_AI_Text_Model($modelMetadata, $providerMetadata);
    }
}

class Cloudflare_AI_Provider_Availability implements ProviderAvailabilityInterface, WithRequestAuthenticationInterface
{
    use WithRequestAuthenticationTrait;

    public function isConfigured(): bool
    {
        $key = '';
        if ($this->requestAuthentication instanceof ApiKeyRequestAuthentication) {
            $key = $this->requestAuthentication->getApiKey();
        }
        $account = '';
        if (function_exists('cloudflare_ai_search_settings')) {
            $settings = cloudflare_ai_search_settings();
            $account = $settings['account_id'];
        }
        $credentials = cloudflare_ai_provider_resolve_credentials($key, $account);
        return $credentials['account_id'] !== '' && $credentials['token'] !== '';
    }
}

class Cloudflare_AI_Model_Directory implements ModelMetadataDirectoryInterface
{
    public function listModelMetadata(): array
    {
        $models = array();
        foreach (cloudflare_ai_provider_catalog() as $entry) {
            if ($entry['kind'] === 'speech' && !class_exists('Cloudflare_AI_Speech_Model', false)) {
                continue;
            }
            $models[] = cloudflare_ai_provider_model_metadata($entry);
        }
        return $models;
    }

    public function hasModelMetadata(string $modelId): bool
    {
        return $this->find($modelId) !== null;
    }

    public function getModelMetadata(string $modelId): ModelMetadata
    {
        $entry = $this->find($modelId);
        if ($entry === null) {
            throw new InvalidArgumentException(esc_html__('That Cloudflare model is not in the catalog.', 'skyphusion-ai'));
        }
        return cloudflare_ai_provider_model_metadata($entry);
    }

    private function find(string $modelId): ?array
    {
        $entry = cloudflare_ai_provider_catalog_entry($modelId);
        if ($entry === null) {
            return null;
        }
        if ($entry['kind'] === 'speech' && !class_exists('Cloudflare_AI_Speech_Model', false)) {
            return null;
        }
        return $entry;
    }
}

function cloudflare_ai_provider_model_metadata(array $entry): ModelMetadata
{
    $capabilities = array();
    $options = array();
    if ($entry['kind'] === 'text') {
        $capabilities[] = CapabilityEnum::textGeneration();
        $capabilities[] = CapabilityEnum::chatHistory();
        $modalities = array(array(ModalityEnum::text()));
        if ($entry['vision']) {
            $modalities[] = array(ModalityEnum::image());
            $modalities[] = array(ModalityEnum::text(), ModalityEnum::image());
        }
        $options[] = new SupportedOption(OptionEnum::inputModalities(), $modalities);
        $options[] = new SupportedOption(OptionEnum::outputModalities(), array(array(ModalityEnum::text())));
        foreach (array('systemInstruction', 'maxTokens', 'temperature', 'topP', 'topK', 'candidateCount', 'stopSequences', 'presencePenalty', 'frequencyPenalty') as $option) {
            $options[] = new SupportedOption(OptionEnum::{$option}());
        }
        if ($entry['tools']) {
            $options[] = new SupportedOption(OptionEnum::functionDeclarations());
        }
        if ($entry['json']) {
            $options[] = new SupportedOption(OptionEnum::outputMimeType());
            $options[] = new SupportedOption(OptionEnum::outputSchema());
        }
    } elseif ($entry['kind'] === 'image') {
        $capabilities[] = CapabilityEnum::imageGeneration();
        $options[] = new SupportedOption(OptionEnum::inputModalities(), array(array(ModalityEnum::text())));
        $options[] = new SupportedOption(OptionEnum::outputModalities(), array(array(ModalityEnum::image())));
    } else {
        $capabilities[] = CapabilityEnum::speechGeneration();
        $options[] = new SupportedOption(OptionEnum::inputModalities(), array(array(ModalityEnum::text())));
        $options[] = new SupportedOption(OptionEnum::outputModalities(), array(array(ModalityEnum::audio())));
        $options[] = new SupportedOption(OptionEnum::outputSpeechVoice());
    }

    return new ModelMetadata($entry['id'], $entry['name'], $capabilities, $options);
}

/**
 * POST a JSON body. The bearer is the token half of the Connectors key.
 *
 * @return array{status: int, data: array<string, mixed>|null, body: string, content_type: string}
 */
function cloudflare_ai_provider_post(AbstractApiBasedModel $model, $url, array $payload)
{
    if ($url === '') {
        throw new RuntimeException(esc_html__('Add the Cloudflare account ID before using Workers AI.', 'skyphusion-ai'));
    }
    $key = '';
    try {
        $auth = $model->getRequestAuthentication();
        if ($auth instanceof ApiKeyRequestAuthentication) {
            $key = $auth->getApiKey();
        }
    } catch (RuntimeException $e) {
        $key = '';
    }
    $account = '';
    if (function_exists('cloudflare_ai_search_settings')) {
        $account = cloudflare_ai_search_settings()['account_id'];
    }
    $credentials = cloudflare_ai_provider_resolve_credentials($key, $account);
    if ($credentials['token'] === '' || $credentials['account_id'] === '') {
        throw new RuntimeException(esc_html__('Cloudflare Workers AI is not connected. Add the account ID and the API token on Connectors.', 'skyphusion-ai'));
    }

    $request = new Request(
        HttpMethodEnum::post(),
        $url,
        array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $credentials['token'],
        ),
        $payload
    );
    $response = $model->getHttpTransporter()->send($request, $model->getRequestOptions());
    $data = $response->getData();
    if (!$response->isSuccessful()) {
        throw new RuntimeException(esc_html(cloudflare_ai_provider_error_message($response->getStatusCode(), $data)));
    }
    $type = $response->getHeaderAsString('Content-Type');
    return array(
        'status' => $response->getStatusCode(),
        'data' => $data,
        'body' => (string) $response->getBody(),
        'content_type' => $type === null ? '' : $type,
    );
}

function cloudflare_ai_provider_gateway_from_settings()
{
    if (!function_exists('cloudflare_ai_search_settings')) {
        return '';
    }
    return cloudflare_ai_search_settings()['gateway_id'];
}

function cloudflare_ai_provider_account_from_settings()
{
    if (!function_exists('cloudflare_ai_search_settings')) {
        return '';
    }
    return cloudflare_ai_search_settings()['account_id'];
}

/**
 * Account id and token for one model call.
 *
 * The key prefix wins, then the saved account id, then CLOUDFLARE_ACCOUNT_ID.
 * A missing authenticator is an empty key, not an exception for the caller.
 *
 * @return array{account_id: string, token: string}
 */
function cloudflare_ai_provider_request_account(AbstractApiBasedModel $model)
{
    $key = '';
    try {
        $auth = $model->getRequestAuthentication();
        if ($auth instanceof ApiKeyRequestAuthentication) {
            $key = $auth->getApiKey();
        }
    } catch (RuntimeException $e) {
        $key = '';
    }
    return cloudflare_ai_provider_resolve_credentials($key, cloudflare_ai_provider_account_from_settings());
}

class Cloudflare_AI_Text_Model extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $entry = cloudflare_ai_provider_catalog_entry($this->metadata()->getId());
        $config = $this->getConfig();
        $spec = array(
            'model' => $this->metadata()->getId(),
            'system' => $config->getSystemInstruction(),
            'messages' => cloudflare_ai_provider_prompt_messages($prompt),
            'temperature' => $config->getTemperature(),
            'max_tokens' => $config->getMaxTokens(),
            'top_p' => $config->getTopP(),
            'top_k' => $config->getTopK(),
            'frequency_penalty' => $config->getFrequencyPenalty(),
            'presence_penalty' => $config->getPresencePenalty(),
            'n' => $config->getCandidateCount(),
            'stop' => $config->getStopSequences(),
        );
        $mime = $config->getOutputMimeType();
        if ($entry && $entry['json'] && $mime === 'application/json') {
            $spec['json'] = true;
            $spec['schema'] = $config->getOutputSchema();
        }
        if ($entry && $entry['tools']) {
            $declarations = $config->getFunctionDeclarations();
            if (is_array($declarations)) {
                $tools = array();
                foreach ($declarations as $declaration) {
                    $tools[] = array(
                        'name' => $declaration->getName(),
                        'description' => $declaration->getDescription(),
                        'parameters' => $declaration->getParameters(),
                    );
                }
                $spec['tools'] = $tools;
            }
        }

        $account = cloudflare_ai_provider_request_account($this);
        $url = cloudflare_ai_provider_chat_url($account['account_id'], cloudflare_ai_provider_gateway_from_settings());
        $posted = cloudflare_ai_provider_post($this, $url, cloudflare_ai_provider_chat_body($spec));
        $parsed = cloudflare_ai_provider_read_text($posted['data']);
        if (!$parsed['candidates']) {
            throw new RuntimeException(esc_html__('Cloudflare returned no text.', 'skyphusion-ai'));
        }

        $candidates = array();
        foreach ($parsed['candidates'] as $candidate) {
            $parts = array();
            if ($candidate['text'] !== '') {
                $parts[] = new MessagePart($candidate['text']);
            }
            foreach ($candidate['tool_calls'] as $call) {
                $id = $call['id'] !== '' ? $call['id'] : null;
                $name = $call['name'] !== '' ? $call['name'] : null;
                $parts[] = new MessagePart(new FunctionCall($id, $name, $call['arguments']));
            }
            if (!$parts) {
                $parts[] = new MessagePart('');
            }
            $finish = FinishReasonEnum::stop();
            if ($candidate['finish'] === 'length') {
                $finish = FinishReasonEnum::length();
            } elseif ($candidate['finish'] === 'content_filter') {
                $finish = FinishReasonEnum::contentFilter();
            } elseif ($candidate['finish'] === 'tool_calls') {
                $finish = FinishReasonEnum::toolCalls();
            } elseif ($candidate['finish'] === 'error') {
                $finish = FinishReasonEnum::error();
            }
            $candidates[] = new Candidate(new Message(MessageRoleEnum::model(), $parts), $finish);
        }

        $usage = $parsed['usage'];
        return new GenerativeAiResult(
            $parsed['id'] !== '' ? $parsed['id'] : 'cloudflare',
            $candidates,
            new TokenUsage($usage['prompt'], $usage['completion'], $usage['total']),
            $this->providerMetadata(),
            $this->metadata()
        );
    }
}

class Cloudflare_AI_Image_Model extends AbstractApiBasedModel implements ImageGenerationModelInterface
{
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $text = cloudflare_ai_provider_prompt_text($prompt);
        if ($text === '') {
            throw new InvalidArgumentException(esc_html__('Image generation needs a text prompt.', 'skyphusion-ai'));
        }
        $account = cloudflare_ai_provider_request_account($this);
        $url = cloudflare_ai_provider_run_url(
            $account['account_id'],
            cloudflare_ai_provider_gateway_from_settings(),
            $this->metadata()->getId()
        );
        $posted = cloudflare_ai_provider_post($this, $url, array('prompt' => $text));
        $image = cloudflare_ai_provider_read_image($posted['data']);
        if ($image === '') {
            throw new RuntimeException(esc_html__('Cloudflare returned no image.', 'skyphusion-ai'));
        }
        $file = new File('data:image/jpeg;base64,' . $image);
        return new GenerativeAiResult(
            'cloudflare',
            array(new Candidate(
                new Message(MessageRoleEnum::model(), array(new MessagePart($file))),
                FinishReasonEnum::stop()
            )),
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata()
        );
    }
}

/**
 * @param list<Message> $prompt
 * @return array<int, array<string, mixed>>
 */
function cloudflare_ai_provider_prompt_messages(array $prompt)
{
    $messages = array();
    foreach ($prompt as $message) {
        $role = $message->getRole()->isModel() ? 'assistant' : 'user';
        $text = array();
        $images = array();
        $tool_calls = array();
        $tool_responses = array();
        foreach ($message->getParts() as $part) {
            if ($part->getType()->isText()) {
                $text[] = (string) $part->getText();
            } elseif ($part->getType()->isFile()) {
                $file = $part->getFile();
                if ($file !== null && $file->isImage()) {
                    $url = $file->getDataUri();
                    if ($url === null || $url === '') {
                        $url = $file->getUrl();
                    }
                    if (is_string($url) && $url !== '') {
                        $images[] = $url;
                    }
                }
            } elseif ($part->getType()->isFunctionCall()) {
                $call = $part->getFunctionCall();
                if ($call !== null) {
                    $tool_calls[] = array(
                        'id' => (string) $call->getId(),
                        'name' => (string) $call->getName(),
                        'arguments' => $call->getArgs(),
                    );
                }
            } elseif ($part->getType()->isFunctionResponse()) {
                $response = $part->getFunctionResponse();
                if ($response !== null) {
                    $tool_responses[] = array(
                        'role' => 'tool',
                        'tool_call_id' => (string) $response->getId(),
                        'content' => $response->getResponse(),
                    );
                }
            }
        }
        if ($tool_responses && !$text && !$images && !$tool_calls) {
            foreach ($tool_responses as $tool_response) {
                $messages[] = $tool_response;
            }
            continue;
        }
        $messages[] = array(
            'role' => $role,
            'text' => trim(implode("\n", $text)),
            'images' => $images,
            'tool_calls' => $tool_calls,
        );
        foreach ($tool_responses as $tool_response) {
            $messages[] = $tool_response;
        }
    }
    return $messages;
}

/**
 * @param list<Message> $prompt
 */
function cloudflare_ai_provider_prompt_text(array $prompt)
{
    $chunks = array();
    foreach (cloudflare_ai_provider_prompt_messages($prompt) as $message) {
        if (isset($message['text']) && $message['text'] !== '') {
            $chunks[] = $message['text'];
        }
    }
    return trim(implode("\n", $chunks));
}
