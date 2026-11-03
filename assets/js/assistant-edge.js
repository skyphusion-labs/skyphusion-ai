(function () {
	var forms = document.querySelectorAll(".skyphusion-ai-assistant-edge");
	forms.forEach(function (form) {
		form._turns = [];
		form.addEventListener("submit", function (event) {
			event.preventDefault();
			var input = form.querySelector("input");
			var asked = input.value;
			var out = form.querySelector(".skyphusion-ai-assistant-stream");
			var transcript = form.querySelector(".skyphusion-ai-assistant-transcript");
			var askedLine = document.createElement("p");
			askedLine.textContent = asked;
			transcript.appendChild(askedLine);
			out.textContent = "";
			var keep = parseInt(form.getAttribute("data-keep"), 10);
			if (isNaN(keep) || keep < 0) {
				keep = 10;
			}
			if (keep > 10) {
				keep = 10;
			}
			fetch(form.getAttribute("data-worker"), {
				method: "POST",
				headers: { "content-type": "application/json" },
				body: JSON.stringify({
					question: asked,
					model: form.getAttribute("data-model"),
					turns: form._turns,
					keep: keep,
				}),
			}).then(function (response) {
				var reader = response.body.getReader();
				var decoder = new TextDecoder();
				var pending = "";
				function pump() {
					return reader.read().then(function (result) {
						if (result.done) {
							var reply = document.createElement("p");
							reply.textContent = out.textContent;
							transcript.appendChild(reply);
							form._turns.push({ question: asked, answer: out.textContent });
							out.textContent = "";
							return;
						}
						pending += decoder.decode(result.value, { stream: true });
						var parts = pending.split("\n");
						pending = parts.pop();
						parts.forEach(function (line) {
							line = line.trim();
							if (line.indexOf("data:") !== 0) {
								return;
							}
							var data = line.slice(5).trim();
							if (!data || data === "[DONE]") {
								return;
							}
							try {
								var parsed = JSON.parse(data);
								if (parsed.text) {
									out.textContent += parsed.text;
								}
								if (parsed.citations && parsed.citations.length) {
									var list = document.createElement("ul");
									parsed.citations.forEach(function (row) {
										if (!row) {
											return;
										}
										var title = row.title || "";
										var url = row.url || "";
										if (!title && !url) {
											return;
										}
										var item = document.createElement("li");
										var image = row.image || "";
										if (image.indexOf("https://") === 0 || image.indexOf("http://") === 0) {
											var pic = document.createElement("img");
											pic.src = image;
											pic.alt = title;
											item.appendChild(pic);
										}
										if (url.indexOf("https://") === 0 || url.indexOf("http://") === 0) {
											var link = document.createElement("a");
											link.href = url;
											link.textContent = title;
											item.appendChild(link);
										} else if (title) {
											item.appendChild(document.createTextNode(title));
										}
										var lines = row.lines || [];
										for (var p = 0; p < lines.length; p++) {
											if (!lines[p]) {
												continue;
											}
											var fact = document.createElement("p");
											fact.textContent = lines[p];
											item.appendChild(fact);
										}
										list.appendChild(item);
									});
									transcript.appendChild(list);
								}
							} catch (error) {}
						});
						return pump();
					});
				}
				return pump();
			});
		});
	});
})();
