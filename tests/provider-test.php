<?php
/**
 * Workers AI routing checks. Loaded by tests/endpoint-test.php.
 * Stand-in account ids and tokens only.
 */

$account = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$other = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$model = '@cf/baai/bge-large-en-v1.5';
$llama = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';

expect('account accepted', cloudflare_ai_provider_account_id(strtoupper($account)), $account);
expect('account rejected', cloudflare_ai_provider_account_id('not-an-account'), '');
expect('gateway accepted', cloudflare_ai_provider_gateway_id('store_1'), 'store_1');
expect('gateway rejected', cloudflare_ai_provider_gateway_id('../store'), '');
expect('gateway capital rejected', cloudflare_ai_provider_gateway_id('Store'), '');
expect('model accepted', cloudflare_ai_provider_model_id($model), $model);
expect('model traversal rejected', cloudflare_ai_provider_model_id('@cf/meta/../../etc'), '');
expect('model url rejected', cloudflare_ai_provider_model_id('https://evil.example/ai'), '');

$split = cloudflare_ai_provider_credentials(strtoupper($account) . ':only-the-token', $other);
expect('prefix account wins', $split['account_id'], $account);
expect('token is the suffix', $split['token'], 'only-the-token');

$plain = cloudflare_ai_provider_credentials('plain-token', $account);
expect('plain token kept', $plain['token'], 'plain-token');
expect('setting account used', $plain['account_id'], $account);

$spaced = cloudflare_ai_provider_credentials($account . ':bad token', '');
expect('spaced token rejected', $spaced['token'], '');

expect(
    'chat direct',
    cloudflare_ai_provider_chat_url($account, ''),
    'https://api.cloudflare.com/client/v4/accounts/' . $account . '/ai/v1/chat/completions'
);
expect(
    'chat gateway',
    cloudflare_ai_provider_chat_url($account, 'store'),
    'https://gateway.ai.cloudflare.com/v1/' . $account . '/store/workers-ai/v1/chat/completions'
);
expect('chat without account', cloudflare_ai_provider_chat_url('nope', 'store'), '');
expect(
    'run direct',
    cloudflare_ai_provider_run_url($account, '', $model),
    'https://api.cloudflare.com/client/v4/accounts/' . $account . '/ai/run/' . $model
);
expect(
    'run gateway',
    cloudflare_ai_provider_run_url($account, 'store', $model),
    'https://gateway.ai.cloudflare.com/v1/' . $account . '/store/workers-ai/' . $model
);
expect('run bad model', cloudflare_ai_provider_run_url($account, '', '@cf/meta/../../etc'), '');

$catalog = cloudflare_ai_provider_catalog();
expect('default text model first', $catalog[0]['id'], $llama);
expect('vision model second', $catalog[1]['id'], '@cf/mistralai/mistral-small-3.1-24b-instruct');
expect('embeddings model', cloudflare_ai_provider_embeddings_model(), $model);

$chat = cloudflare_ai_provider_chat_body(array(
    'model' => $llama,
    'system' => 'Be brief',
    'messages' => array(
        array('role' => 'model', 'text' => 'Hello'),
    ),
    'json' => true,
    'temperature' => 0.2,
    'tools' => array(
        array('name' => 'lookup', 'description' => 'Look up a page'),
    ),
));
expect('system role', $chat['messages'][0]['role'], 'system');
expect('model role mapped', $chat['messages'][1]['role'], 'assistant');
expect('json object format', $chat['response_format']['type'], 'json_object');
expect('temperature kept', $chat['temperature'], 0.2);
expect(
    'tool parameters object',
    $chat['tools'][0]['function']['parameters']['properties'] instanceof stdClass,
    true
);

$text = cloudflare_ai_provider_read_text(array(
    'id' => 'gen-1',
    'choices' => array(
        array(
            'finish_reason' => 'stop',
            'message' => array('content' => 'A shirt'),
        ),
    ),
    'usage' => array('prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5),
));
expect('openai text', $text['candidates'][0]['text'], 'A shirt');
expect('openai usage', $text['usage']['total'], 5);

$envelope = cloudflare_ai_provider_read_text(array(
    'result' => array('response' => 'From the run endpoint'),
));
expect('run text', $envelope['candidates'][0]['text'], 'From the run endpoint');

expect(
    'image prefix stripped',
    cloudflare_ai_provider_read_image(array('result' => array('image' => 'data:image/jpeg;base64,QUJD'))),
    'QUJD'
);

$vectors = cloudflare_ai_provider_read_embeddings(array(
    'result' => array('data' => array(array(0.5, 1))),
));
expect('bge vector', $vectors, array(array(0.5, 1.0)));
$openai_vectors = cloudflare_ai_provider_read_embeddings(array(
    'data' => array(array('embedding' => array(0.25))),
));
expect('openai vector', $openai_vectors, array(array(0.25)));

$search = skyphusion_ai_read_answer(array(
    'response' => 'Ships in two days.',
    'data' => array(
        array('content' => 'Shipping page'),
        array('text' => 'Another page'),
    ),
));
expect('search answer', $search['answer'], 'Ships in two days.');
expect('search sources', $search['sources'], array('Shipping page', 'Another page'));

$public_chat = skyphusion_ai_read_answer(array(
    'choices' => array(
        array('message' => array('content' => 'Ships in two days.')),
    ),
    'chunks' => array(
        array('text' => 'Shipping page'),
        array('text' => 'Another page'),
    ),
));
expect('chat answer', $public_chat['answer'], 'Ships in two days.');
expect('chat sources', $public_chat['sources'], array('Shipping page', 'Another page'));

$retrieved = skyphusion_ai_read_answer(array(
    'success' => true,
    'result' => array(
        'chunks' => array(
            array('text' => 'Beanie page'),
            array('content' => array(array('text' => 'Care page'))),
        ),
    ),
));
expect('retrieved answer', $retrieved['answer'], '');
expect('retrieved sources', $retrieved['sources'], array('Beanie page', 'Care page'));

$parts = skyphusion_ai_read_answer(array(
    'data' => array(
        array('content' => array(
            array('text' => 'First part'),
            array('text' => 'Second part'),
        )),
    ),
));
expect('content parts', $parts['sources'], array("First part\nSecond part"));

expect(
    'bearer redacted',
    cloudflare_ai_provider_error_message(401, array('errors' => array(array('message' => 'Bad Bearer stand-in-token')))),
    'Bad Bearer [redacted]'
);

// THE OTHER BRANCH. With no message in the payload the builder falls back to
// a translated sentence, and that path fataled in this harness the moment
// `__()` arrived in it, because every existing case went down the branch
// above. One uncovered branch is all it takes.
expect(
    'no message falls back to the status sentence',
    cloudflare_ai_provider_error_message(503, array()),
    'Cloudflare returned HTTP 503.'
);
expect(
    'and an undecodable body does the same',
    cloudflare_ai_provider_error_message(502, null),
    'Cloudflare returned HTTP 502.'
);

$speech = cloudflare_ai_provider_speech_body('Hello', 'Angus');
expect('speech speaker', $speech['speaker'], 'angus');
expect('speech encoding', $speech['encoding'], 'mp3');
$unknown = cloudflare_ai_provider_speech_body('Hello', 'nobody');
expect('unknown voice omitted', isset($unknown['speaker']), false);

$links = cloudflare_ai_provider_links($account);
expect('workers link', $links['workers_ai'], 'https://dash.cloudflare.com/' . $account . '/ai/workers-ai');
expect('gateway link', $links['gateway'], 'https://dash.cloudflare.com/' . $account . '/ai/ai-gateway');
expect('search link', $links['search'], 'https://dash.cloudflare.com/' . $account . '/ai/ai-search');
$empty_links = cloudflare_ai_provider_links('');
expect(
    'workers link without account',
    $empty_links['workers_ai'],
    'https://dash.cloudflare.com/?to=/:account/ai/workers-ai'
);

putenv('CLOUDFLARE_ACCOUNT_ID=' . $other);
$from_env = cloudflare_ai_provider_resolve_credentials('plain-token', '');
expect('account from env', $from_env['account_id'], $other);
putenv('CLOUDFLARE_ACCOUNT_ID');

$order = shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/account-order.php') . ' ' . escapeshellarg(dirname(__DIR__) . '/includes/routes.php')
);
expect('env beats constant', trim((string) $order), $other);
