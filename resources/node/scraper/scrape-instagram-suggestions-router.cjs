#!/usr/bin/env node

// Router fuer Suggestion-Flows: leitet an den Basic- oder DeepSearch-Flow weiter.

const { runInstagramSuggestionsBasicFlow } = require('./scrape-instagram-suggestions-basic-flow.cjs');
const { runInstagramSuggestionsDeepSearchFlow } = require('./scrape-instagram-suggestions-deepsearch-flow.cjs');

async function runProfileSuggestionConnectionScan(
  deps,
  page,
  runtimeState,
  notes,
  targetUsername,
  profileUrl,
  options = {},
) {
  const deepSearch = options?.deepSearch === true;

  if (deepSearch) {
    return runInstagramSuggestionsDeepSearchFlow(
      deps,
      page,
      runtimeState,
      notes,
      targetUsername,
      profileUrl,
      options,
    );
  }

  return runInstagramSuggestionsBasicFlow(
    deps,
    page,
    runtimeState,
    notes,
    targetUsername,
    profileUrl,
    options,
  );
}

module.exports = {
  runProfileSuggestionConnectionScan,
};
