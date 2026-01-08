<div class="p-2 bg-gray-50 dark:bg-gray-500/10 rounded-t-xl">
    <div class="px-4 py-2">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
            Summary
        </h3>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 p-4">
        <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Cases</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['totalCases']) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg MTTR (Mins)</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['avgMttr'], 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg MTBF (Days)</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['avgMtbf'], 2) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Potential Loss</p>
            <p class="text-xl font-bold text-gray-900 dark:text-white">Rp {{ number_format($stats['totalPotentialFundLoss'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Loss</p>
            <p class="text-xl font-bold text-red-600 dark:text-red-500">Rp {{ number_format($stats['totalFundLoss'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Recovered Fund</p>
            <p class="text-xl font-bold text-green-600 dark:text-green-500">Rp {{ number_format($stats['totalRecoveredFund'], 0, ',', '.') }}</p>
        </div>
    </div>
</div>
