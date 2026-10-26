/**
 * Site assistant stream. Runs on the merchant account.
 * The AI binding is named AI. This file has no API token.
 * It does not call Skyphusion.
 * A chat of more than ten turns sends one short summary of the older turns.
 * The summary is one text call. The page still shows every turn.
 * This file does not store the chat and does not read an IP address.
 */
const EMBED_MODEL = '@cf/baai/bge-large-en-v1.5';

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
		const pages = await skyphusionAiPages(env, question);
		if (payload.action === 'retrieve') {
			headers.set('content-type', 'application/json; charset=utf-8');
			return new Response(JSON.stringify({ pages: pages }), { headers: headers });
		}
		const model = String(payload.model || env.MODEL || '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
		const earlier = await skyphusionAiEarlier(env, model, payload.turns, payload.keep);
		let prompt = skyphusionAiPrompt(question, pages);
		if (earlier) {
			prompt += '\n' + earlier;
		}
		const aiStream = await env.AI.run(model, {
			messages: [{ role: 'user', content: prompt }],
			stream: true,
		});
		headers.set('content-type', 'text/event-stream; charset=utf-8');
		headers.set('cache-control', 'no-cache');
		return new Response(skyphusionAiEvents(aiStream), { headers: headers });
	},
	async scheduled(event, env) {
		await skyphusionAiBackfill(env);
	},
};

async function skyphusionAiPages(env, question) {
	if (!env.AI || !env.VECTORIZE) {
		return [];
	}
	const embedded = await env.AI.run(EMBED_MODEL, { text: [question] });
	let vector = null;
	if (embedded && Array.isArray(embedded.data)) {
		if (Array.isArray(embedded.data[0])) {
			vector = embedded.data[0];
		} else if (typeof embedded.data[0] === 'number') {
			vector = embedded.data;
		}
	}
	if (!vector) {
		return [];
	}
	const found = await env.VECTORIZE.query(vector, { topK: 20, returnMetadata: 'all' });
	const rows = found && found.matches ? found.matches : [];
	const pages = [];
	const seen = {};
	for (const row of rows) {
		const meta = row.metadata || {};
		const key = String(meta.post_id || meta.url || '');
		if (key && seen[key]) {
			continue;
		}
		if (key) {
			seen[key] = true;
		}
		pages.push({
			title: String(meta.title || ''),
			url: String(meta.url || ''),
		});
		if (pages.length >= 5) {
			break;
		}
	}
	return pages;
}

function skyphusionAiKeep(value) {
	let keep = Number(value);
	if (!Number.isFinite(keep)) {
		keep = 10;
	}
	keep = Math.floor(keep);
	if (keep < 0) {
		keep = 0;
	}
	if (keep > 10) {
		keep = 10;
	}
	return keep;
}

function skyphusionAiPairs(turns) {
	const pairs = [];
	const rows = Array.isArray(turns) ? turns : [];
	for (const row of rows) {
		if (!row || typeof row !== 'object') {
			continue;
		}
		const asked = String(row.question || '').trim();
		const reply = String(row.answer || '').trim();
		if (!asked && !reply) {
			continue;
		}
		pairs.push('Visitor: ' + asked + '\nAssistant: ' + reply);
	}
	return pairs;
}

function skyphusionAiReplyText(result) {
	if (!result) {
		return '';
	}
	if (typeof result.response === 'string') {
		return result.response.trim();
	}
	if (result.choices && result.choices[0] && result.choices[0].message && typeof result.choices[0].message.content === 'string') {
		return result.choices[0].message.content.trim();
	}
	return '';
}

async function skyphusionAiEarlier(env, model, turns, keepValue) {
	const keep = skyphusionAiKeep(keepValue);
	if (keep < 1) {
		return '';
	}
	const pairs = skyphusionAiPairs(turns);
	if (pairs.length <= 10) {
		return pairs.slice(-keep).join('\n');
	}
	const older = pairs.slice(0, pairs.length - keep);
	const latest = pairs.slice(-keep);
	let block = '';
	if (env.AI && older.length) {
		const summary = await env.AI.run(model, {
			messages: [{
				role: 'user',
				content: 'Summarize these earlier turns in one short block.\n' + older.join('\n'),
			}],
		});
		block = skyphusionAiReplyText(summary);
	}
	const tail = latest.join('\n');
	if (!block) {
		return tail;
	}
	return 'Earlier talk:\n' + block + '\n' + tail;
}

function skyphusionAiPrompt(question, pages) {
	const lines = [];
	for (const page of pages) {
		if (page.title) {
			lines.push(page.title);
		}
	}
	return 'Answer from these pages only.\n' + lines.join('\n') + '\nQuestion: ' + question;
}

async function skyphusionAiBackfill(env) {
	if (!env.SITE_URL || !env.BACKFILL_SECRET || !env.AI) {
		return;
	}
	const site = String(env.SITE_URL).replace(/\/$/, '');
	const headers = { 'x-skyphusion-worker': env.BACKFILL_SECRET };
	const next = await fetch(site + '/wp-json/skyphusion-ai/v1/backfill-next', {
		method: 'POST',
		headers: headers,
	});
	if (!next.ok) {
		return;
	}
	const job = await next.json();
	if (!job || job.state !== 'ready' || !job.prompt) {
		return;
	}
	const result = await env.AI.run(job.model || env.MODEL, {
		messages: [{ role: 'user', content: job.prompt }],
	});
	const text = result && typeof result.response === 'string' ? result.response : '';
	if (!text) {
		return;
	}
	await fetch(site + '/wp-json/skyphusion-ai/v1/backfill-save', {
		method: 'POST',
		headers: {
			'content-type': 'application/json',
			'x-skyphusion-worker': env.BACKFILL_SECRET,
		},
		body: JSON.stringify({
			post_id: job.post_id,
			kind: job.kind,
			text: text,
		}),
	});
}

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
