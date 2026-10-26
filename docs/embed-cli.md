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

`wp skyphusion-ai embed retry` stores posts whose last store did not finish.
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
