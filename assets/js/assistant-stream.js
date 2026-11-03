(function () {
	var forms = document.querySelectorAll(".skyphusion-ai-assistant-php");
	forms.forEach(function (form) {
		form._turns = [];
		var nodes = form.getElementsByTagName("input");
		var questions = [];
		var answers = [];
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i].name === "skyphusion_ai_assistant_turn_question[]") {
				questions.push(nodes[i].value);
			}
			if (nodes[i].name === "skyphusion_ai_assistant_turn_answer[]") {
				answers.push(nodes[i].value);
			}
		}
		var count = questions.length;
		if (answers.length < count) {
			count = answers.length;
		}
		for (var n = 0; n < count; n++) {
			form._turns.push({ question: questions[n], answer: answers[n] });
		}
		form.addEventListener("submit", function (event) {
			event.preventDefault();
			var input = form.querySelector("input[type=text]");
			var asked = input.value;
			var out = form.querySelector(".skyphusion-ai-assistant-stream");
			var transcript = form.querySelector(".skyphusion-ai-assistant-transcript");
			var askedLine = document.createElement("p");
			askedLine.textContent = asked;
			transcript.appendChild(askedLine);
			out.textContent = "";
			var body = new FormData();
			body.append("action", "skyphusion_ai_assistant_stream");
			body.append("_wpnonce", form.getAttribute("data-nonce") || "");
			body.append("skyphusion_ai_assistant_question", asked);
			var consent = form.querySelector("input[type=checkbox]");
			if (consent && consent.checked) {
				body.append("skyphusion_ai_assistant_consent", "1");
			}
			form._turns.forEach(function (turn) {
				body.append("skyphusion_ai_assistant_turn_question[]", turn.question);
				body.append("skyphusion_ai_assistant_turn_answer[]", turn.answer);
			});
			fetch(form.getAttribute("data-stream"), {
				method: "POST",
				body: body,
				credentials: "same-origin",
			}).then(function (response) {
				if (!response.body || !response.body.getReader) {
					return;
				}
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
							input.value = "";
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
