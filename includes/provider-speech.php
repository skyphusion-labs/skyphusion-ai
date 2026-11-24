<?php
/**
 * Speech generation through Workers AI Aura. Loaded only when the AI Client has the interface.
 *
 * @package Skyphusion_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Models\SpeechGeneration\Contracts\SpeechGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

class Cloudflare_AI_Speech_Model extends AbstractApiBasedModel implements SpeechGenerationModelInterface {

	public function generateSpeechResult( array $prompt ): GenerativeAiResult {
		$text = cloudflare_ai_provider_prompt_text( $prompt );
		if ( $text === '' ) {
			throw new InvalidArgumentException( esc_html__( 'Speech generation needs text.', 'skyphusion-ai' ) );
		}
		$voice   = $this->getConfig()->getOutputSpeechVoice();
		$account = cloudflare_ai_provider_request_account( $this );
		$url     = cloudflare_ai_provider_run_url(
			$account['account_id'],
			cloudflare_ai_provider_gateway_from_settings(),
			$this->metadata()->getId()
		);
		$posted  = cloudflare_ai_provider_post(
			$this,
			$url,
			cloudflare_ai_provider_speech_body( $text, $voice === null ? '' : $voice )
		);

		$audio = cloudflare_ai_provider_audio_data_uri( $posted['content_type'], $posted['body'], $posted['data'] );
		if ( $audio === '' ) {
			throw new RuntimeException( esc_html__( 'Cloudflare returned no audio.', 'skyphusion-ai' ) );
		}

		return new GenerativeAiResult(
			'cloudflare',
			array(
				new Candidate(
					new Message( MessageRoleEnum::model(), array( new MessagePart( new File( $audio ) ) ) ),
					FinishReasonEnum::stop()
				),
			),
			new TokenUsage( 0, 0, 0 ),
			$this->providerMetadata(),
			$this->metadata()
		);
	}
}

/**
 * @param array<string, mixed>|null $decoded
 */
function cloudflare_ai_provider_audio_data_uri( $content_type, $body, $decoded ) {
	$content_type = strtolower( (string) $content_type );
	$body         = (string) $body;
	if ( $body !== '' && ( strpos( $content_type, 'audio/' ) === 0 || strpos( $content_type, 'application/octet-stream' ) === 0 ) ) {
		return 'data:audio/mpeg;base64,' . base64_encode( $body );
	}
	if ( ! is_array( $decoded ) ) {
		return '';
	}
	$audio = '';
	if ( isset( $decoded['result']['audio'] ) && is_string( $decoded['result']['audio'] ) ) {
		$audio = $decoded['result']['audio'];
	} elseif ( isset( $decoded['audio'] ) && is_string( $decoded['audio'] ) ) {
		$audio = $decoded['audio'];
	}
	if ( $audio === '' ) {
		return '';
	}
	if ( strpos( $audio, 'base64,' ) !== false ) {
		return $audio;
	}
	return 'data:audio/mpeg;base64,' . $audio;
}
