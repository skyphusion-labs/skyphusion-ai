# Embedding commands

`wp skyphusion-ai embed` runs the Vectorize jobs.
`wp skyphusion-ai related` builds and prints related lists.
The vectors stay in the merchant's Cloudflare account.
The commands do not send the text to Skyphusion.

`wp skyphusion-ai embed status` prints the index, the switch, and the local counts.
Add `--store` to also print the index's own vector count.
That count is `vectorCount` from the index info call.
The info call is documented at https://developers.cloudflare.com/api/resources/vectorize/.

`wp skyphusion-ai embed estimate` prints passages, neurons, days, and stored dimensions.
It does not call the model.
A post is split into passages of about 500 characters with a short overlap before Vectorize stores it.

`wp skyphusion-ai embed run` stores the posts that changed.
`wp skyphusion-ai embed reconcile` deletes vectors for posts that no longer belong.
`wp skyphusion-ai embed rebuild` stores the selection again.
Those three commands stop while embeddings are off.
They do not call the model in that case.

Run and rebuild go in steps.
One step stores at most 25 posts, in post id order.
Add `--batch=N` to set the step size, from 1 to 200.
After each post, the plugin saves the last post id it did.
The next step starts after that id.
If a step stops part-way, only the post it was on is sent again.
Each step prints one `embed_step=` line.
The last line is `embed_state=ran` when every post is done.
If the daily embeddings limit stops a step, the last line is `embed_state=limit`, and the next step runs about an hour later.
A run that stopped resumes at the saved id.

The settings button runs one step.
After that, WP-Cron runs one step about every minute until the run is done.
A site with no WP-CLI and no WP-Cron can call the same step over REST.
Send `POST /wp-json/skyphusion-ai/v1/embed/step` as an admin, with an optional `batch` parameter.
The reply gives `state`, `processed`, `embedded`, `after`, and `remaining`.
Call it again while `state` is `step`.
The run is done when `state` is `done`.

`wp skyphusion-ai embed retry` stores posts whose last store did not finish.

Status, estimate, retry, reconcile, move, and `related refresh` read the posts in batches too.
They use the same post ids and the same batch size as a run, 25 by default.
Add `--batch=N` to set the size, from 1 to 200.
No command loads every post at once.
Each prints `embed_batches=` (or `related_batches=`), the number of batches it read.
These commands do not change the saved position of a run that is in progress.
Move creates the other index once, then reads and writes one batch at a time.
If one batch cannot be copied, the move stops there with `embed_state=blocked`.
`wp skyphusion-ai embed search` followed by the words prints matches.
Search does not write the index.

`wp skyphusion-ai embed purge` deletes the index and turns embeddings off.
`wp skyphusion-ai embed delete-index` does the same only with `--yes`.

`wp skyphusion-ai embed move --index=other-name` copies stored vectors into that Vectorize index.
The copy reads vectors by id and upserts them.
It does not call the embeddings model.
It spends no neurons.
Create uses the same model preset when that preset exists.
The upsert format is documented at https://developers.cloudflare.com/api/resources/vectorize/subresources/indexes/methods/upsert/.

`wp skyphusion-ai related refresh` stores related lists for the current selection.
`wp skyphusion-ai related refresh 123` stores the list for that post.
`wp skyphusion-ai related show 123` prints each saved row with its score and the reason.
A page view still reads the index when no list is saved.
Neither command calls the embeddings model.
The settings screen places that list after the content, before the content, or after one paragraph.
Each row can say why it matches.
The why line uses terms stored on the vectors, or the stored score.
It does not call a model.

There is no WP-CLI command for backfill, translation, or usage.
