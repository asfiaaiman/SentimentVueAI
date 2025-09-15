<script setup lang="ts">
import { computed, ref } from 'vue';
import { useAppearance } from '@/composables/useAppearance';

const text = ref('');
const loading = ref(false);
const error = ref<string | null>(null);
const result = ref<{ label: string; confidence: number; emotion_label?: string | null; emotion_confidence?: number | null } | null>(null);
const history = ref<Array<{ t: number; label: 'positive' | 'negative' | 'neutral' | 'unknown'; confidence: number; emotion_label?: string | null }>>([]);

const counts = computed(() => {
    const c = { positive: 0, negative: 0, neutral: 0, unknown: 0 } as Record<string, number>;
    for (const h of history.value) c[h.label] = (c[h.label] ?? 0) + 1;
    return c;
});

const sentimentSegments = computed(() => {
    const total = Object.values(counts.value).reduce((a, b) => a + b, 0);
    if (!total) return [] as Array<{ label: string; pct: number; color: string }>;
    const colorFor = (label: string) => label === 'positive' ? '#16a34a' : label === 'negative' ? '#dc2626' : label === 'neutral' ? '#374151' : '#6b7280';
    return Object.entries(counts.value)
        .filter(([_, c]) => c > 0)
        .map(([label, c]) => ({ label, pct: (c / total) * 100, color: colorFor(label) }));
});

const chart = computed(() => {
    const data = history.value.slice(-30);
    const width = 600;
    const height = 160;
    if (data.length === 0) return { width, height, path: '', points: [] as Array<{ x: number; y: number; color: string; label: string; confidence: number; }> };
    const stepX = data.length > 1 ? width / (data.length - 1) : 0;
    const toY = (v: number) => height - v * height; // confidence in [0,1]
    const pts = data.map((d, i) => ({ x: i * stepX, y: toY(Math.max(0, Math.min(1, d.confidence))), label: d.label, confidence: d.confidence }));
    const path = pts.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ');
    const colorFor = (label: string) => label === 'positive' ? '#16a34a' : label === 'negative' ? '#dc2626' : label === 'neutral' ? '#374151' : '#6b7280';
    const points = pts.map(p => ({ ...p, color: colorFor(p.label) }));
    return { width, height, path, points };
});

const emotionCounts = computed(() => {
    const counter = new Map<string, number>();
    for (const h of history.value) {
        const key = (h.emotion_label || 'unknown').toLowerCase();
        counter.set(key, (counter.get(key) || 0) + 1);
    }
    return counter;
});

const emotionSegments = computed(() => {
    const counts = emotionCounts.value;
    const total = Array.from(counts.values()).reduce((a, b) => a + b, 0);
    if (!total) return [] as Array<{ label: string; pct: number; color: string }>;
    const colorFor = (label: string) => {
        const l = label.toLowerCase();
        if (l.includes('joy') || l.includes('happy')) return '#16a34a';
        if (l.includes('anger') || l.includes('angry') || l.includes('disgust')) return '#dc2626';
        if (l.includes('sad')) return '#1d4ed8';
        if (l.includes('fear')) return '#7c3aed';
        if (l.includes('surprise')) return '#f59e0b';
        if (l.includes('neutral')) return '#374151';
        return '#6b7280';
    };
    return Array.from(counts.entries()).map(([label, c]) => ({
        label,
        pct: (c / total) * 100,
        color: colorFor(label),
    }));
});

const { appearance, updateAppearance } = useAppearance();

async function analyze() {
    error.value = null;
    result.value = null;
    if (!text.value.trim()) {
        error.value = 'Please enter some text.';
        return;
    }
    loading.value = true;
    try {
        const response = await fetch('/api/sentiment/analyze', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ text: text.value }),
        });
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(body.message || 'Request failed');
        }
        const data = await response.json();
        result.value = data;
        history.value.push({ t: Date.now(), label: (data.label || 'unknown'), confidence: Number(data.confidence || 0), emotion_label: data.emotion_label || null });
    } catch (e: any) {
        error.value = e?.message || 'Something went wrong';
    } finally {
        loading.value = false;
    }
}

const csvUploading = ref(false);
const csvError = ref<string | null>(null);
const csvResults = ref<Array<{ text: string; label: string; confidence: number; emotion_label?: string | null; emotion_confidence?: number | null }>>([]);
const handle = ref('');
const handleLoading = ref(false);
const handleError = ref<string | null>(null);
const handleResults = ref<Array<{ text: string; label: string; confidence: number; emotion_label?: string | null; emotion_confidence?: number | null }>>([]);

async function uploadCsv(event: Event) {
    csvError.value = null;
    const input = event.target as HTMLInputElement;
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];
    const formData = new FormData();
    formData.append('file', file);
    formData.append('has_header', 'true');
    formData.append('column', 'text');
    csvUploading.value = true;
    try {
        const response = await fetch('/api/sentiment/batch-csv', {
            method: 'POST',
            body: formData,
        });
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(body.message || 'Upload failed');
        }
        const data = await response.json();
        csvResults.value = data.items || [];
    } catch (e: any) {
        csvError.value = e?.message || 'Something went wrong';
    } finally {
        csvUploading.value = false;
        if (input) input.value = '';
    }
}

async function analyzeHandle() {
    handleError.value = null;
    handleResults.value = [];
    if (!handle.value.trim()) return;
    handleLoading.value = true;
    try {
        const response = await fetch(`/api/sentiment/handle?handle=${encodeURIComponent(handle.value)}&limit=20`);
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(body.message || 'Request failed');
        }
        const data = await response.json();
        handleResults.value = data.items || [];
    } catch (e: any) {
        handleError.value = e?.message || 'Something went wrong';
    } finally {
        handleLoading.value = false;
    }
}
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <div class="mx-auto max-w-3xl p-6">
            <h1 class="text-2xl font-semibold text-gray-900 mb-4">Sentiment Analysis</h1>
            <div class="bg-white dark:bg-gray-900 shadow rounded p-4 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-600 dark:text-gray-300">Theme: {{ appearance }}</div>
                    <div class="inline-flex items-center gap-2">
                        <button @click="updateAppearance('light')" class="px-2 py-1 text-sm rounded border hover:bg-gray-50 dark:hover:bg-gray-800">Light</button>
                        <button @click="updateAppearance('dark')" class="px-2 py-1 text-sm rounded border hover:bg-gray-50 dark:hover:bg-gray-800">Dark</button>
                        <button @click="updateAppearance('system')" class="px-2 py-1 text-sm rounded border hover:bg-gray-50 dark:hover:bg-gray-800">System</button>
                    </div>
                </div>
                <textarea
                    v-model="text"
                    class="w-full min-h-[140px] border rounded p-3 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-100"
                    placeholder="Enter text to analyze..."
                />

                <div class="flex items-center gap-3">
                    <button
                        class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50"
                        :disabled="loading"
                        @click="analyze"
                    >
                        {{ loading ? 'Analyzing...' : 'Analyze' }}
                    </button>
                    <a href="/" class="text-sm text-gray-600 hover:text-gray-900">Back to Home</a>
                    <button
                        v-if="history.length > 0"
                        class="ml-auto text-sm text-gray-600 hover:text-gray-900"
                        @click="history = []"
                    >Clear history</button>
                </div>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <div v-if="result" class="mt-2">
                    <p class="text-gray-800 dark:text-gray-100">
                        <span class="font-medium">Label:</span>
                        <span class="uppercase ml-1" :class="{
                            'text-green-600': result?.label === 'positive',
                            'text-red-600': result?.label === 'negative',
                            'text-gray-700': result?.label === 'neutral',
                        }">{{ result.label }}</span>
                    </p>
                    <p class="text-gray-800 dark:text-gray-100">
                        <span class="font-medium">Confidence:</span>
                        <span class="ml-1">{{ (result.confidence * 100).toFixed(1) }}%</span>
                    </p>
                    <div v-if="result?.emotion_label" class="text-gray-800 dark:text-gray-100">
                        <span class="font-medium">Emotion:</span>
                        <span class="ml-1 capitalize">{{ result.emotion_label }}</span>
                        <span v-if="result.emotion_confidence != null" class="ml-1 text-gray-600">({{ ((result.emotion_confidence || 0) * 100).toFixed(1) }}%)</span>
                    </div>
                </div>

                <div v-if="history.length" class="mt-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-2">Recent trend (confidence)</h2>
                    <div class="overflow-x-auto">
                        <svg :width="chart.width" :height="chart.height" class="bg-gray-50 border rounded">
                            <path :d="chart.path" fill="none" stroke="#6366f1" stroke-width="2" />
                            <g>
                                <circle v-for="(p, i) in chart.points" :key="i" :cx="p.x" :cy="p.y" r="3" :fill="p.color">
                                    <title>#{{ i + 1 }} {{ p.label }} ({{ (p.confidence * 100).toFixed(0) }}%)</title>
                                </circle>
                            </g>
                        </svg>
                    </div>
                    <div class="mt-3 grid grid-cols-4 gap-2 text-sm">
                        <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-green-600"></span><span class="text-gray-700">Positive: {{ counts.positive }}</span></div>
                        <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-red-600"></span><span class="text-gray-700">Negative: {{ counts.negative }}</span></div>
                        <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-gray-700"></span><span class="text-gray-700">Neutral: {{ counts.neutral }}</span></div>
                        <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-gray-400"></span><span class="text-gray-700">Unknown: {{ counts.unknown }}</span></div>
                    </div>

                    <h2 class="text-lg font-medium text-gray-900 mt-6 mb-2">Sentiment distribution</h2>
                    <div v-if="sentimentSegments.length" class="w-full bg-gray-100 rounded h-4 overflow-hidden border">
                        <div
                            v-for="(seg, i) in sentimentSegments"
                            :key="i"
                            class="h-4 inline-block"
                            :style="{ width: seg.pct + '%', backgroundColor: seg.color }"
                        >
                            <span class="sr-only">{{ seg.label }} {{ seg.pct.toFixed(0) }}%</span>
                        </div>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-3 text-sm">
                        <span v-for="(seg, i) in sentimentSegments" :key="i" class="inline-flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: seg.color }"></span>
                            <span class="text-gray-700 capitalize">{{ seg.label }}: {{ seg.pct.toFixed(0) }}%</span>
                        </span>
                    </div>

                    <h2 class="text-lg font-medium text-gray-900 mt-6 mb-2">Emotions distribution</h2>
                    <div v-if="emotionSegments.length" class="w-full bg-gray-100 rounded h-4 overflow-hidden border">
                        <div
                            v-for="(seg, i) in emotionSegments"
                            :key="i"
                            class="h-4 inline-block"
                            :style="{ width: seg.pct + '%', backgroundColor: seg.color }"
                        >
                            <span class="sr-only">{{ seg.label }} {{ seg.pct.toFixed(0) }}%</span>
                        </div>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-3 text-sm">
                        <span v-for="(seg, i) in emotionSegments" :key="i" class="inline-flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: seg.color }"></span>
                            <span class="text-gray-700 capitalize">{{ seg.label }}: {{ seg.pct.toFixed(0) }}%</span>
                        </span>
                    </div>
                </div>

                <div class="mt-8 border-t pt-4">
                    <h2 class="text-lg font-medium text-gray-900 mb-2">Batch analysis (CSV)</h2>
                    <div class="flex items-center gap-3">
                        <input type="file" accept=".csv,text/csv" @change="uploadCsv" />
                        <span v-if="csvUploading" class="text-sm text-gray-600">Uploading & analyzing…</span>
                    </div>
                    <p v-if="csvError" class="text-sm text-red-600 mt-2">{{ csvError }}</p>
                    <div v-if="csvResults.length" class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-600">
                                    <th class="py-2 pr-4">Text</th>
                                    <th class="py-2 pr-4">Sentiment</th>
                                    <th class="py-2 pr-4">Conf.</th>
                                    <th class="py-2 pr-4">Emotion</th>
                                    <th class="py-2 pr-4">Conf.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in csvResults" :key="i" class="border-t">
                                    <td class="py-2 pr-4 max-w-[420px] truncate" :title="row.text">{{ row.text }}</td>
                                    <td class="py-2 pr-4 capitalize">{{ row.label }}</td>
                                    <td class="py-2 pr-4">{{ (row.confidence * 100).toFixed(0) }}%</td>
                                    <td class="py-2 pr-4 capitalize">{{ row.emotion_label || '-' }}</td>
                                    <td class="py-2 pr-4">{{ row.emotion_confidence != null ? ((row.emotion_confidence || 0) * 100).toFixed(0) + '%' : '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-8 border-t pt-4">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Analyze Twitter/X handle</h2>
                    <div class="flex items-center gap-3">
                        <input v-model="handle" type="text" placeholder="@handle" class="border rounded p-2 dark:bg-gray-800 dark:text-gray-100" />
                        <button @click="analyzeHandle" class="px-3 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700" :disabled="handleLoading">
                            {{ handleLoading ? 'Loading…' : 'Analyze' }}
                        </button>
                    </div>
                    <p v-if="handleError" class="text-sm text-red-600 mt-2">{{ handleError }}</p>
                    <div v-if="handleResults.length" class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-600">
                                    <th class="py-2 pr-4">Text</th>
                                    <th class="py-2 pr-4">Sentiment</th>
                                    <th class="py-2 pr-4">Conf.</th>
                                    <th class="py-2 pr-4">Emotion</th>
                                    <th class="py-2 pr-4">Conf.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in handleResults" :key="i" class="border-t">
                                    <td class="py-2 pr-4 max-w-[420px] truncate" :title="row.text">{{ row.text }}</td>
                                    <td class="py-2 pr-4 capitalize">{{ row.label }}</td>
                                    <td class="py-2 pr-4">{{ (row.confidence * 100).toFixed(0) }}%</td>
                                    <td class="py-2 pr-4 capitalize">{{ row.emotion_label || '-' }}</td>
                                    <td class="py-2 pr-4">{{ row.emotion_confidence != null ? ((row.emotion_confidence || 0) * 100).toFixed(0) + '%' : '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</template>


