ARG PYTHON_IMAGE=python:3.12.10-slim@sha256:fd95fa221297a88e1cf49c55ec1828edd7c5a428187e67b5d1805692d11588db

FROM ${PYTHON_IMAGE} AS builder

ENV PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /build

RUN python -m pip install --no-cache-dir \
    setuptools==80.9.0 \
    wheel==0.45.1

COPY pyproject.toml README.md requirements-container.lock ./
COPY src ./src

RUN python -m pip wheel \
        --no-build-isolation \
        --no-deps \
        --wheel-dir /wheels \
        . \
    && python -m pip wheel \
        --no-deps \
        --wheel-dir /wheels \
        --requirement requirements-container.lock

FROM ${PYTHON_IMAGE} AS runtime

ARG SOURCE_REVISION
ARG BUILD_DATE

LABEL org.opencontainers.image.title="Workeyx ML Recommendation Service" \
    org.opencontainers.image.description="Internal production-oriented FastAPI inference runtime for Job recommendations" \
    org.opencontainers.image.version="0.2.0" \
    org.opencontainers.image.revision="${SOURCE_REVISION}" \
    org.opencontainers.image.source="https://gitlab.com/engakthamnaeem/workey" \
    org.opencontainers.image.created="${BUILD_DATE}" \
    com.workeyx.ml.bundle.version="job-rec-inference-bundle-v1" \
    com.workeyx.ml.model.version="xgbranker-tuned-v1" \
    com.workeyx.ml.feature-schema.version="job-rec-features-v1"

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_NO_CACHE_DIR=1 \
    ML_BUNDLE_DIR=/app/data/bundles/recommendation/v1 \
    ML_MAX_JOBS_PER_REQUEST=500 \
    ML_MAX_RESULTS=100 \
    ML_DOCS_ENABLED=false \
    PORT=8100

RUN groupadd --gid 10001 mlruntime \
    && useradd \
        --uid 10001 \
        --gid 10001 \
        --no-create-home \
        --home-dir /nonexistent \
        --shell /usr/sbin/nologin \
        mlruntime

COPY --from=builder /wheels /wheels

RUN python -m pip install --no-index --no-deps /wheels/*.whl \
    && rm -rf /wheels \
    && rm -rf \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/baselines \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/evaluation \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/splits \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/training \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/tuning \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/data/__init__.py \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/data/__main__.py \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/data/generator.py \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/schemas/baselines.py \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/schemas/final_evaluation.py \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/schemas/splits.py \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/schemas/training.py \
        /usr/local/lib/python3.12/site-packages/smart_recruitment_ml/schemas/tuning.py \
    && find /usr/local/lib/python3.12/site-packages/smart_recruitment_ml \
        -type d -name __pycache__ -prune -exec rm -rf '{}' +

WORKDIR /app

COPY --chown=0:0 data/bundles/recommendation/v1 /app/data/bundles/recommendation/v1
COPY --chown=0:0 container/start.py container/healthcheck.py /app/container/

RUN find /app -type d -exec chmod 0555 '{}' + \
    && find /app -type f -exec chmod 0444 '{}' + \
    && chmod 0555 /app/container/start.py /app/container/healthcheck.py

USER 10001:10001

EXPOSE 8100
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD ["python", "/app/container/healthcheck.py"]

ENTRYPOINT ["python", "/app/container/start.py"]
CMD []
