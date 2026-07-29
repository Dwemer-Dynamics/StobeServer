#!/usr/bin/env python3
"""Generate reviewed Stobe World Knowledge rows from an approved Kenshi Wiki manifest."""

from __future__ import annotations

import argparse
import csv
import hashlib
import html
import json
import os
import re
import time
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

OPENROUTER_API_URL = "https://openrouter.ai/api/v1/chat/completions"
DEFAULT_MODEL = "z-ai/glm-5.2"
CSV_FIELDS = [
    "topic",
    "topic_desc",
    "topic_desc_basic",
    "knowledge_class",
    "knowledge_class_basic",
    "aliases",
    "tags",
]


class WikiTextExtractor(HTMLParser):
    """Reduce rendered wiki HTML to readable prose while dropping data-heavy tables."""

    def __init__(self) -> None:
        super().__init__()
        self.parts: list[str] = []
        self.skip_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if self.skip_depth > 0:
            self.skip_depth += 1
            return
        if tag in {"table", "script", "style", "figure", "aside", "nav"}:
            self.skip_depth = 1
        elif tag in {"p", "h1", "h2", "h3", "h4", "li", "br"}:
            self.parts.append("\n")

    def handle_endtag(self, tag: str) -> None:
        if self.skip_depth > 0:
            self.skip_depth -= 1
            return
        if tag in {"p", "h1", "h2", "h3", "h4", "li"}:
            self.parts.append("\n")

    def handle_data(self, data: str) -> None:
        if self.skip_depth == 0:
            self.parts.append(data)

    def text(self) -> str:
        value = html.unescape(" ".join(self.parts))
        value = re.sub(r"\[\s*\d+\s*\]", "", value)
        value = re.sub(r"[ \t]+", " ", value)
        value = re.sub(r"\s*\n\s*", "\n", value)
        return value.strip()


def fetch_json(
    url: str,
    *,
    method: str = "GET",
    payload: dict[str, Any] | None = None,
    headers: dict[str, str] | None = None,
    timeout: int = 120,
    retries: int = 4,
) -> dict[str, Any]:
    request_headers = {
        "User-Agent": "StobeWorldKnowledgeGenerator/1.0 (+https://dwemerdynamics.com)",
    }
    if headers:
        request_headers.update(headers)
    body = None if payload is None else json.dumps(payload).encode("utf-8")

    for attempt in range(1, retries + 1):
        request = Request(url, data=body, headers=request_headers, method=method)
        try:
            with urlopen(request, timeout=timeout) as response:
                return json.loads(response.read().decode("utf-8"))
        except HTTPError as exc:
            retryable = exc.code in {408, 409, 425, 429, 500, 502, 503, 504}
            if attempt >= retries or not retryable:
                error_body = exc.read().decode("utf-8", errors="replace")
                raise RuntimeError(f"HTTP {exc.code} for {url}: {error_body[:500]}") from exc
        except (URLError, TimeoutError, json.JSONDecodeError) as exc:
            if attempt >= retries:
                raise RuntimeError(f"Request failed for {url}: {exc}") from exc
        time.sleep(float(attempt))

    raise RuntimeError(f"Request failed for {url}")


def fetch_wiki_article(api_url: str, title: str) -> dict[str, Any]:
    params = {
        "action": "query",
        "prop": "revisions",
        "redirects": "1",
        "rvprop": "ids|timestamp",
        "titles": title,
        "format": "json",
        "formatversion": "2",
    }
    response = fetch_json(f"{api_url}?{urlencode(params)}")
    pages = response.get("query", {}).get("pages", [])
    if not isinstance(pages, list) or len(pages) != 1 or pages[0].get("missing"):
        raise RuntimeError(f"Wiki article not found: {title}")

    page = pages[0]
    parse_params = {
        "action": "parse",
        "page": str(page.get("title", title)),
        "prop": "text|revid",
        "redirects": "1",
        "format": "json",
        "formatversion": "2",
    }
    parsed_page = fetch_json(f"{api_url}?{urlencode(parse_params)}").get("parse", {})
    parser = WikiTextExtractor()
    parser.feed(str(parsed_page.get("text", "")))
    extract = parser.text()
    if len(extract) < 200:
        raise RuntimeError(f"Wiki article extract is unexpectedly short: {title}")
    revision = (page.get("revisions") or [{}])[0]
    return {
        "page_id": int(page.get("pageid", 0)),
        "resolved_title": str(page.get("title", title)),
        "revision_id": int(revision.get("revid", 0)),
        "revision_timestamp": str(revision.get("timestamp", "")),
        "extract": extract,
        "extract_sha256": hashlib.sha256(extract.encode("utf-8")).hexdigest(),
    }


def extract_json_object(value: str) -> dict[str, Any]:
    text = value.strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*", "", text, flags=re.IGNORECASE)
        text = re.sub(r"\s*```$", "", text)
    start = text.find("{")
    end = text.rfind("}")
    if start < 0 or end <= start:
        raise RuntimeError("GLM response did not contain a JSON object.")
    parsed = json.loads(text[start : end + 1])
    if not isinstance(parsed, dict):
        raise RuntimeError("GLM response JSON was not an object.")
    return parsed


def extract_openrouter_content(response: dict[str, Any]) -> str:
    choices = response.get("choices", [])
    if not isinstance(choices, list) or not choices:
        return ""
    content = choices[0].get("message", {}).get("content", "")
    if isinstance(content, str):
        return content
    if isinstance(content, list):
        return "\n".join(
            str(item.get("text", ""))
            for item in content
            if isinstance(item, dict) and item.get("type") == "text"
        )
    return ""


def generate_summary(
    api_key: str,
    model: str,
    topic: str,
    source_url: str,
    article_text: str,
    timeout: int,
    retry_feedback: str = "",
) -> str:
    system_prompt = (
        "You create concise World Knowledge entries for the Kenshi game. "
        "Use only the supplied wiki article. Paraphrase rather than quote. "
        "Write 2 to 4 factual sentences about stable identity, affiliation, role, and lore. "
        "Exclude statistics, equipment, combat performance, prices, rewards, recruitment mechanics, "
        "player menus, strategies, spawn details, trivia, and world-state consequences. "
        "Never speculate or use phrases such as may be, might be, possibly, or presumably. "
        "Do not claim whether the subject is currently alive, dead, imprisoned, allied, or hostile, "
        "because a live playthrough addendum supplies that state. "
        'Return strict JSON only: {"summary":"..."}.'
    )
    user_prompt = (
        f"Topic: {topic}\n"
        f"Source: {source_url}\n\n"
        f"Wiki article:\n{article_text}\n"
    )
    if retry_feedback:
        user_prompt += f"\nThe previous draft was rejected: {retry_feedback}\n"
    response = fetch_json(
        OPENROUTER_API_URL,
        method="POST",
        payload={
            "model": model,
            "temperature": 0.2,
            "response_format": {"type": "json_object"},
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
        },
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "HTTP-Referer": "https://dwemerdynamics.com/stobe",
            "X-Title": "Stobe Kenshi World Knowledge Generator",
        },
        timeout=timeout,
    )
    parsed = extract_json_object(extract_openrouter_content(response))
    return re.sub(r"\s+", " ", str(parsed.get("summary", "")).strip())


def validate_summary(topic: str, summary: str) -> list[str]:
    errors: list[str] = []
    sentences = [part for part in re.split(r"(?<=[.!?])\s+", summary) if part.strip()]
    if len(sentences) < 2 or len(sentences) > 4:
        errors.append(f"{topic}: expected 2-4 sentences, got {len(sentences)}")
    if len(summary) < 120 or len(summary) > 1000:
        errors.append(f"{topic}: summary length {len(summary)} is outside 120-1000 characters")
    if "\n" in summary or summary.startswith(("-", "*")):
        errors.append(f"{topic}: summary must be plain prose")
    forbidden = (
        "currently alive",
        "currently dead",
        "currently imprisoned",
        "in the player's playthrough",
        "according to the wiki",
        "unique recruit",
        "recruitable",
        "join the player",
        "at no cost",
        "character edit",
        "meitou",
        "ai core",
        "weapon",
        "armor",
        "armour",
        "removal triggers",
        "may be",
        "might be",
        "possibly",
        "presumably",
    )
    lowered = summary.lower()
    for phrase in forbidden:
        if phrase in lowered:
            errors.append(f"{topic}: forbidden state/source phrase '{phrase}'")
    return errors


def read_api_key(path: Path | None) -> str:
    env_key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    if env_key:
        return env_key
    if path and path.is_file():
        return path.read_text(encoding="utf-8").strip()
    return ""


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Generate targeted Stobe World Knowledge rows with OpenRouter GLM."
    )
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--output-csv", type=Path, required=True)
    parser.add_argument("--report-json", type=Path, required=True)
    parser.add_argument("--api-key-file", type=Path)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--request-timeout", type=int, default=120)
    parser.add_argument("--max-article-chars", type=int, default=14000)
    args = parser.parse_args()

    manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
    topics = manifest.get("topics", [])
    if not isinstance(topics, list) or not topics:
        raise RuntimeError("Manifest does not contain any topics.")

    api_key = read_api_key(args.api_key_file)

    api_url = str(manifest.get("wiki_api", "")).strip()
    if not api_url:
        raise RuntimeError("Manifest wiki_api is required.")

    rows: list[dict[str, str]] = []
    sources: list[dict[str, Any]] = []
    validation_errors: list[str] = []
    seen_topics: set[str] = set()
    cached_report: dict[str, Any] = {}
    cached_sources: dict[str, dict[str, Any]] = {}
    if args.report_json.is_file():
        try:
            cached_report = json.loads(args.report_json.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            cached_report = {}
        if cached_report.get("model") == args.model:
            cached_sources = {
                str(source.get("topic", "")).casefold(): source
                for source in cached_report.get("sources", [])
                if isinstance(source, dict)
            }
    cache_hits = 0

    for index, item in enumerate(topics, start=1):
        topic = str(item.get("topic", "")).strip()
        wiki_title = str(item.get("wiki_title", topic)).strip()
        source_url = str(item.get("source_url", "")).strip()
        key = topic.casefold()
        if not topic or key in seen_topics:
            raise RuntimeError(f"Missing or duplicate manifest topic: {topic!r}")
        seen_topics.add(key)

        print(f"[{index}/{len(topics)}] Fetching {wiki_title}")
        source = fetch_wiki_article(api_url, wiki_title)
        errors: list[str] = []
        summary = ""
        cached_source = cached_sources.get(key, {})
        cached_summary = str(cached_source.get("summary", "")).strip()
        if (
            cached_source.get("extract_sha256") == source["extract_sha256"]
            and not validate_summary(topic, cached_summary)
        ):
            summary = cached_summary
            cache_hits += 1
            print(f"[CACHE] Reused reviewed summary for {topic}")
        else:
            if not api_key:
                raise RuntimeError(
                    f"OPENROUTER_API_KEY or --api-key-file is required to generate {topic}."
                )
            for attempt in range(1, 4):
                summary = generate_summary(
                    api_key,
                    args.model,
                    topic,
                    source_url,
                    source["extract"][: max(1000, args.max_article_chars)],
                    max(30, args.request_timeout),
                    "; ".join(errors),
                )
                errors = validate_summary(topic, summary)
                if not errors:
                    break
                if attempt < 3:
                    print(f"[RETRY] {topic}: {'; '.join(errors)}")
        errors = validate_summary(topic, summary)
        validation_errors.extend(errors)
        rows.append(
            {
                "topic": topic,
                "topic_desc": "",
                "topic_desc_basic": summary,
                "knowledge_class": "",
                "knowledge_class_basic": str(item.get("knowledge_class_basic", "")).strip(),
                "aliases": ", ".join(str(value).strip() for value in item.get("aliases", []) if str(value).strip()),
                "tags": ", ".join(str(value).strip() for value in item.get("tags", []) if str(value).strip()),
            }
        )
        sources.append(
            {
                "topic": topic,
                "source_url": source_url,
                "page_id": source["page_id"],
                "resolved_title": source["resolved_title"],
                "revision_id": source["revision_id"],
                "revision_timestamp": source["revision_timestamp"],
                "extract_sha256": source["extract_sha256"],
                "summary": summary,
            }
        )

    if validation_errors:
        raise RuntimeError("Generated output failed validation:\n" + "\n".join(validation_errors))

    args.output_csv.parent.mkdir(parents=True, exist_ok=True)
    with args.output_csv.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=CSV_FIELDS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)

    report = {
        "schema_version": 1,
        "generated_at": (
            cached_report.get("generated_at")
            if cache_hits == len(rows) and cached_report.get("generated_at")
            else datetime.now(timezone.utc).isoformat()
        ),
        "model": args.model,
        "manifest_sha256": hashlib.sha256(args.manifest.read_bytes()).hexdigest(),
        "topic_count": len(rows),
        "cache_hits": cache_hits,
        "validation": {"passed": True, "errors": []},
        "sources": sources,
    }
    args.report_json.parent.mkdir(parents=True, exist_ok=True)
    args.report_json.write_text(
        json.dumps(report, indent=2, ensure_ascii=True) + "\n",
        encoding="utf-8",
    )
    print(f"Generated {len(rows)} World Knowledge rows with {args.model}.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
