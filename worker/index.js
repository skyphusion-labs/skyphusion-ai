/**
 * Site assistant stream. Runs on the merchant account.
 * The AI binding is named AI. This file has no API token.
 * It does not call Skyphusion.
 */
export default {
	async fetch(request, env) {
		const headers = skyphusionAiCors(env);
		if (request.method === 'OPTIONS') {
			return new Response(null, { status: 204, headers: headers });
		}
		if (request.method !== 'POST') {
			return new Response('Send a POST request.', { status: 405, headers: headers });
		}
		const origin = request.headers.get('Origin') || '';
		if (env.ALLOWED_ORIGIN && origin !== env.ALLOWED_ORIGIN) {
			return new Response('This site is not allowed.', { status: 403, headers: headers });
		}
		let payload = {};
		try {
			payload = await request.json();
		} catch (error) {
			payload = {};
		}
		const question = String(payload.question || '').trim();
		if (!question) {
			return new Response('The question is empty.', { status: 400, headers: headers });
		}
		const model = String(payload.model || env.MODEL || '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
		const aiStream = await env.AI.run(model, {
			messages: [{ role: 'user', content: question }],
			stream: true,
		});
		headers.set('content-type', 'text/event-stream; charset=utf-8');
		headers.set('cache-control', 'no-cache');
		return new Response(skyphusionAiEvents(aiStream), { headers: headers });
	},
};

function skyphusionAiCors(env) {
	const headers = new Headers();
	headers.set('access-control-allow-origin', env.ALLOWED_ORIGIN || '');
	headers.set('access-control-allow-methods', 'POST, OPTIONS');
	headers.set('access-control-allow-headers', 'content-type');
	return headers;
}

function skyphusionAiEvents(aiStream) {
	const encoder = new TextEncoder();
	const decoder = new TextDecoder();
	return new ReadableStream({
		async start(controller) {
			const reader = aiStream.getReader();
			let buffer = '';
			while (true) {
				const chunk = await reader.read();
				if (chunk.done) {
					break;
				}
				buffer += decoder.decode(chunk.value, { stream: true });
				let split = buffer.indexOf('\n');
				while (split !== -1) {
					const line = buffer.slice(0, split).trim();
					buffer = buffer.slice(split + 1);
					split = buffer.indexOf('\n');
					if (!line.startsWith('data:')) {
						continue;
					}
					const data = line.slice(5).trim();
					if (data === '[DONE]') {
						continue;
					}
					let text = '';
					try {
						const parsed = JSON.parse(data);
						if (typeof parsed.response === 'string') {
							text = parsed.response;
						} else if (parsed.choices && parsed.choices[0] && parsed.choices[0].delta && typeof parsed.choices[0].delta.content === 'string') {
							text = parsed.choices[0].delta.content;
						}
					} catch (error) {
						text = '';
					}
					if (text) {
						controller.enqueue(encoder.encode('data: ' + JSON.stringify({ text: text }) + '\n\n'));
					}
				}
			}
			controller.close();
		},
	});
}
