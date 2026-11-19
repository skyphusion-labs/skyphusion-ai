# Worker

The plugin can deploy one Worker on your Cloudflare account.
You use the button on the settings screen.
You can also run `wp skyphusion-ai worker deploy`.
Both actions call the same function.

A successful deploy saves a `workers.dev` address.
A failed deploy does not save an address.
A blank address keeps the call on your site.
That call streams the answer as it is written.
The page does not show the API token.

The Worker finds matching pages in one step.
It embeds the question with `@cf/baai/bge-large-en-v1.5`.
It reads the Vectorize index on your account.
It does not call your site between those two steps.

The assistant streams the answer from those pages.
A product answer shows the image, the SKU, the stock status, and the managed count.
The answer links to the product.
It does not add the product to the cart.
A chat of more than ten turns does not send every earlier turn to the model.
The Worker summarizes the older turns in one short block.
That summary is one text call.
The page still shows every turn.
The Worker does not store the chat.
The Worker does not store an IP address.
Member search asks for the page list only.
It does not ask for a generated answer.
A logged-out visitor does not call the Worker.

The Worker calls your site once each hour, at minute 0 UTC.
The site checks the limit and builds the prompt.
The Worker runs the model.
The site writes the text.
The site does not run that model on its own hourly task while the Worker address is saved.
A blank address keeps the hourly task on the site.

The Worker does not store posts.
The Worker does not store the neuron ledger.
The public search snippet is a separate script.
The snippet pin stays `0.0.25`.

A saved gateway id goes on each model call.
A blank gateway id leaves those calls on the AI binding.
The option name is gateway.
The five metadata fields match the PHP tags.
The fields are the site, the feature, the model, the user, and the tool.
The user field is empty.
The Worker does not know the WordPress user.
Embeddings stay on `@cf/baai/bge-large-en-v1.5`.
A saved dynamic route changes text calls.
The model id is dynamic/ plus the route.
Embeddings do not use that route.
Vectorize stays on the Vectorize binding.
It does not go through AI Gateway.
Cache follows the cache choice at deploy time.
A cache value of 1 sets 3600 seconds.
A cache value of 0 skips the cache.
A missing choice adds no cache field.
Text calls store the request body only when Log prompts is on.
Embedding calls do not store the request body.
A later gateway change does not update a live Worker.
The settings screen says when that Worker is behind.
Deploy the Worker again to send the new values.
A blank gateway id clears the gateway option on that deploy.
The docs for the binding are at https://developers.cloudflare.com/ai-gateway/usage/worker-binding-methods/
