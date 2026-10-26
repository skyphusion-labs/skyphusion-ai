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
