// Runs the Worker's hourly backfill against a stand-in AI binding and a
// stand-in fetch. Issue #230: a model call that returns the `choices` shape
// (as a dynamic gateway route may) must be saved, not dropped and asked again.
//
// Run: node tests/worker-backfill.mjs   (the PHP suite runs it)
// Prints "ok <label>" or "FAIL <label>"; exits 1 on any FAIL.
import worker from '../worker/index.js';

let failures = 0;
function check(label, actual, expected) {
	if (JSON.stringify(actual) === JSON.stringify(expected)) {
		console.log('ok worker backfill: ' + label);
		return;
	}
	console.log('FAIL worker backfill: ' + label + '\n  actual: ' + JSON.stringify(actual) + '\n  expected: ' + JSON.stringify(expected));
	failures++;
}

async function run(reply) {
	const saved = [];
	const seen = { models: [] };
	globalThis.fetch = async (url, init) => {
		if (String(url).endsWith('/backfill-next')) {
			return new Response(JSON.stringify({ state: 'ready', prompt: 'Summarise this post.', post_id: 7, kind: 'excerpt', model: '@cf/meta/llama-3.3-70b-instruct-fp8-fast' }), { status: 200 });
		}
		if (String(url).endsWith('/backfill-save')) {
			saved.push(JSON.parse(init.body));
			return new Response('{}', { status: 200 });
		}
		return new Response('{}', { status: 404 });
	};
	const env = {
		SITE_URL: 'https://shop.example',
		BACKFILL_SECRET: 'backfill-test-secret',
		GATEWAY_ID: 'store',
		GATEWAY_ROUTE: 'cheap-text',
		AI: {
			run: async (model) => {
				seen.models.push(model);
				return reply;
			},
		},
	};
	await worker.scheduled({}, env);
	return { saved, seen };
}

const routed = await run({ choices: [{ message: { content: ' A short summary. ' } }] });
check('a dynamic route is called with the route model id', routed.seen.models, ['dynamic/cheap-text']);
check('a choices reply is saved', routed.saved.map((row) => row.text), ['A short summary.']);
check('the saved row names the post and kind', routed.saved.map((row) => [row.post_id, row.kind]), [[7, 'excerpt']]);

const plain = await run({ response: 'Plain reply.' });
check('a response reply is still saved', plain.saved.map((row) => row.text), ['Plain reply.']);

const empty = await run({ choices: [] });
check('an empty reply saves nothing', empty.saved, []);

process.exit(failures > 0 ? 1 : 0);
