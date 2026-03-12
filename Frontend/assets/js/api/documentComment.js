async function loadDocumentComments(documentId = null) {
  const query = {};
  if (documentId) {
    query.document_id = documentId;
  }
  return apiRequest("document_comment", "", "GET", null, { query });
}
