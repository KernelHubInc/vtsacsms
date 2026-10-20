FROM python:3.12-slim AS runtime
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1
WORKDIR /service
RUN apt-get update && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-eng \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 10001 kyc \
    && mkdir -p /data/private && chown kyc:kyc /data/private
COPY pyproject.toml requirements.lock ./
RUN pip install -r requirements.lock
COPY app ./app
COPY migrations ./migrations
COPY alembic.ini ./
USER kyc
EXPOSE 8090
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8090/healthz', timeout=3)"
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8090", "--no-access-log"]

FROM runtime AS test
USER root
RUN pip install 'pytest>=8.3,<10' 'ruff>=0.11,<1' 'mypy>=1.15,<2' 'pip-audit>=2.8,<3'
COPY tests ./tests
COPY scripts ./scripts
USER kyc
CMD ["pytest", "-q", "-p", "no:cacheprovider"]
